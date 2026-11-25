# AI assistant (self-hosted model server)

Live Helper Chat can use a language model that runs on your own GPU server. Chat text goes only to the server you configure. Any server with an OpenAI compatible `/v1/chat/completions` endpoint works: vLLM, Ollama, llama.cpp server, LocalAI, TGI with the OpenAI API enabled, or a hosted provider if you choose to use one.

It does two things, and you can switch each on separately:

- **Reply suggestions.** In the chat window an operator clicks the ✨ button next to the send controls. The assistant drafts a reply from the recent conversation and puts it in the message box. The operator edits it and sends it, or discards it. Nothing reaches the visitor without the operator sending it.
- **Automatic answers while the visitor waits** (off by default). This only works in the departments you pick. While a chat is pending and no operator has taken it, the assistant answers the visitor's messages as the bot. It stops and hands the chat to the team in any of these cases:
  - the model replies `[HANDOFF]`, because the system prompt tells it to do so when it is unsure
  - the visitor writes one of the handoff words (default: human, operator, agent, person, representative)
  - the assistant has given the maximum number of answers for the chat (default 6)
  - the model server fails or times out
  - an operator takes the chat

  When it hands off, the visitor sees the handoff message once. The chat stays in the pending queue for operators as normal, and the assistant does not answer in that chat again.

## 1. Run a model server

### Option A: Ollama in this compose stack (simplest)

This needs an NVIDIA GPU and the [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html) on the host.

```
# .env
LHC_AI_BASE_URL=http://ollama:11434/v1
LHC_AI_MODEL=qwen2.5:7b-instruct

docker compose --profile ai up -d
docker compose exec ollama ollama pull qwen2.5:7b-instruct
```

The `ollama` service is not published on the host. Only containers in the stack can reach it. Models are kept in the `ollama-models` volume. `LHC_OLLAMA_GPUS` limits how many GPUs it gets (default `all`).

If you use the `tls` profile as well, start both: `docker compose --profile tls --profile ai up -d`.

### Option B: vLLM on a separate GPU server (best throughput)

```
docker run -d --gpus all -p 8000:8000 --ipc=host vllm/vllm-openai:latest \
  --model Qwen/Qwen2.5-7B-Instruct --max-model-len 8192 --api-key <long random key>
```

```
# .env on the chat server
LHC_AI_BASE_URL=http://gpu-server:8000/v1
LHC_AI_MODEL=Qwen/Qwen2.5-7B-Instruct
LHC_AI_API_KEY=<the same key>        # or LHC_AI_API_KEY_FILE=/run/secrets/ai_key
```

Keep the model server on a private network or behind a firewall that only lets the chat server in, and always set an API key. Put it behind HTTPS if traffic crosses networks you do not control.

### Choosing a model

Use an instruction-tuned model that is good at your visitors' languages. A 7B–8B model (Qwen2.5 7B Instruct, Llama 3.1 8B Instruct, Mistral 7B Instruct) needs about 16 GB of GPU memory in vLLM at 16-bit, or about 6 GB as a 4-bit Ollama model. It typically answers a short chat reply in 1–3 seconds. Larger models give better answers but are slower. Reasoning models work too: `<think>` blocks are removed from the answer.

## 2. Configure it

Go to **System configuration → AI assistant** (needs the `ai/configure` permission).

| Setting | Default | Notes |
|---|---|---|
| Enable the AI assistant | off | Master switch. |
| Base URL, Model, API key | – | `LHC_AI_BASE_URL`, `LHC_AI_MODEL` and `LHC_AI_API_KEY` (or `_FILE`) override these. The fields are then read-only. The key is never shown again after saving. |
| Temperature, Max answer tokens, Timeout | 0.3, 400, 20 s | The timeout covers the whole request to the model server. |
| Chat messages sent as context | 12 | The most recent visitor and operator messages. System messages are not sent. |
| Mask personal data | on | Replaces e-mail addresses, phone numbers, card numbers (Luhn-checked) and IBANs with `[email]`, `[phone]`, `[card]`, `[iban]` before anything leaves the server. |
| Verify TLS certificate | on | Turn it off only for a self-signed certificate on a private network. |
| System prompt | sensible default | Keep the instruction to answer `[HANDOFF]` when unsure. |
| Knowledge | empty | Facts the assistant may use: opening hours, prices, delivery times, policies. It is sent with every request, so keep it short. |
| Reply suggestions | on | Operators also need the `ai/use_suggest` permission. |
| Automatic answers + departments | off, none | Auto-replies only run in the departments you select. |
| Answers per chat before handing off | 6 | |
| Handoff words, handoff message | see above | Words are matched as whole words, case-insensitive. |

**Test connection** sends a one-line request using the values on the form without saving them, and shows the reply and the latency.

### Permissions

Grant `ai/use_suggest` to the operator roles that should see the ✨ button, and `ai/configure` only to administrators. Whoever can configure the assistant chooses which server chat text is sent to, so treat this permission like system configuration. The Administrators group has both by default.

## 3. Privacy and data protection

- Only the message text of the current chat is sent, after masking. The system prompt and knowledge are sent too. Visitor names, e-mail fields, IP addresses, files and chat variables are not sent.
- The masking is pattern-based and will not catch everything, for example a street address. If your visitors share sensitive data, use a model server under your control (that is the point of self-hosting) and add it to your records of processing.
- Every model request is written to the audit log (category `AI`) with the action, chat id, model, latency, token counts and any error. Message text is never logged. Changes to the settings are audited too.
- Ollama and vLLM do not store prompts by default. Check this for any other server you use.

## 4. Monitoring

`/index.php/system/metrics` (see [DEPLOYMENT.md](DEPLOYMENT.md#6-monitoring)) adds:

- `lhc_ai_requests_last_hour{action="suggest|autoreply",result="ok|error"}`
- `lhc_ai_latency_seconds_avg_last_hour`

Suggested alerts: errors above 20 % of requests for 15 minutes (the model server is down or overloaded, so every chat goes to the team); average latency above half the configured timeout.

To see recent requests, open **System configuration → Audit Logs** and filter by category `AI`.

## 5. Things to know

- An automatic answer is generated inside the visitor's "send message" request, after their message has been saved. That request finishes only when the model answers or the timeout is reached, and the bot answer shows up on the widget's next poll. Keep the timeout around 10–20 seconds. A slow or overloaded model server then hands chats off instead of keeping visitors waiting.
- Answers are generated per request, without streaming. Several chats at once mean several parallel requests to the model server. vLLM batches them well; Ollama serves `OLLAMA_NUM_PARALLEL` requests at a time (4 by default on recent versions).
- Bot answers are normal chat messages from the bot (`user_id = -2`). Operators see them in the transcript and in chat history, and they are included in transcripts and exports.
- The assistant state of each chat (answers given, handoff and reason) is stored in the chat variable `lhc_ai`.
- Wrong answers are possible. Start with suggestions only, review the audit log and transcripts, put the facts that matter into **Knowledge**, and then enable automatic answers in one department.
