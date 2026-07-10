const connectBtn = document.getElementById("connectBtn");
const disconnectBtn = document.getElementById("disconnectBtn");
const muteBtn = document.getElementById("muteBtn");
const personId = document.getElementById("personId");

const avatar = document.getElementById("avatar");
const statusText = document.getElementById("statusText");
const statusSubText = document.getElementById("statusSubText");
const remoteAudio = document.getElementById("remoteAudio");
const log = document.getElementById("log");
const debugLog = document.getElementById("debugLog");

let pc = null;
let dc = null;
let localStream = null;
let mouthTimer = null;
let blinkTimer = null;
let connected = false;
let muted = false;

let lastUserTranscript = "";
let lastAssistantTranscript = "";

function nowTime() {
  return new Date().toLocaleTimeString("ja-JP", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
}

function debug(message, data = null) {
  const line = data
    ? `${nowTime()} ${message} ${JSON.stringify(data)}`
    : `${nowTime()} ${message}`;

  console.log(line);

  if (debugLog) {
    debugLog.textContent += line + "\n";
    debugLog.scrollTop = debugLog.scrollHeight;
  }
}

function setStatus(status, label, subLabel) {
  if (!avatar) return;

  avatar.dataset.status = status;

  const labels = {
    idle: "待機中",
    connecting: "接続中",
    listening: "聞いています",
    thinking: "考え中",
    speaking: "話しています",
    error: "エラー",
  };

  const subs = {
    idle: "「Realtime会話をはじめる」を押してください",
    connecting: "Realtime APIに接続しています",
    listening: "そのまま話しかけてください",
    thinking: "きずなちゃんが考えています",
    speaking: "きずなちゃんがお話ししています",
    error: "接続ログを確認してください",
  };

  if (statusText) {
    statusText.textContent = label || labels[status] || labels.idle;
  }

  if (statusSubText) {
    statusSubText.textContent = subLabel || subs[status] || subs.idle;
  }

  if (status === "speaking") {
    startMouthAnimation();
  } else {
    stopMouthAnimation();
  }
}

function addLog(role, content) {
  if (!content || !log) return;

  const item = document.createElement("div");
  item.className = `message ${role}`;
  item.textContent = content;
  log.appendChild(item);
  log.scrollTop = log.scrollHeight;
}

function randomMouthShape() {
  const shapes = ["closed", "small", "medium", "open", "medium", "small"];
  return shapes[Math.floor(Math.random() * shapes.length)];
}

function startMouthAnimation() {
  stopMouthAnimation();

  if (!avatar) return;

  avatar.dataset.mouth = "small";

  mouthTimer = window.setInterval(() => {
    avatar.dataset.mouth = randomMouthShape();
  }, 120);
}

function stopMouthAnimation() {
  if (mouthTimer) {
    window.clearInterval(mouthTimer);
    mouthTimer = null;
  }

  if (avatar) {
    avatar.dataset.mouth = "closed";
  }
}

function startBlinking() {
  stopBlinking();

  blinkTimer = window.setInterval(() => {
    if (!avatar || avatar.dataset.status === "speaking") return;

    avatar.classList.add("is-blinking");

    window.setTimeout(() => {
      avatar.classList.remove("is-blinking");
    }, 140);
  }, 3200);
}

function stopBlinking() {
  if (blinkTimer) {
    window.clearInterval(blinkTimer);
    blinkTimer = null;
  }
}

async function saveRealtimeLog(role, content) {
  const trimmed = (content || "").trim();

  if (!trimmed) return;

  try {
    const response = await fetch("api/realtime_log.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        person_id: Number(personId?.value || 1),
        role,
        content: trimmed,
        source: "realtime",
      }),
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data || data.ok === false) {
      debug("log save failed", data || { status: response.status });
    } else {
      debug("log saved", { role });
    }
  } catch (error) {
    debug("log save error", {
      message: error.message,
    });
  }
}

async function getRealtimeToken() {
  const pid = Number(personId?.value || 1);
  const response = await fetch(`api/realtime_token.php?person_id=${encodeURIComponent(pid)}`);

  const data = await response.json().catch(() => null);

  if (!response.ok || !data || !data.ok) {
    throw new Error(
      data?.message ||
      data?.error ||
      `Realtime token failed: HTTP ${response.status}`
    );
  }

  const token =
    data.value ||
    data.ephemeral_key ||
    data.client_secret?.value;

  if (!token) {
    throw new Error("Realtime token responseに value がありません。");
  }

  return token;
}

function setupDataChannel(channel) {
  dc = channel;

  dc.addEventListener("open", () => {
    debug("data channel open");
    connected = true;
    setStatus("listening", "聞いています", "そのまま話しかけてください");
  });

  dc.addEventListener("close", () => {
    debug("data channel close");
  });

  dc.addEventListener("error", (event) => {
    debug("data channel error", {
      message: event?.message || "unknown",
    });
  });

  dc.addEventListener("message", async (event) => {
    let data = null;

    try {
      data = JSON.parse(event.data);
    } catch (error) {
      debug("non json event", { raw: event.data });
      return;
    }

    handleRealtimeEvent(data);
  });
}

async function handleRealtimeEvent(event) {
  const type = event.type || "";

  if (
    type.includes("error") ||
    type === "session.error"
  ) {
    debug("realtime error", event);
    setStatus("error", "エラー", event.error?.message || "Realtime側でエラーが発生しました");
    return;
  }

  if (
    type === "input_audio_buffer.speech_started" ||
    type === "conversation.item.input_audio_transcription.started"
  ) {
    setStatus("listening", "聞いています", "そのまま話してください");
    return;
  }

  if (
    type === "input_audio_buffer.speech_stopped" ||
    type === "input_audio_buffer.committed"
  ) {
    setStatus("thinking", "考え中", "きずなちゃんが返事を考えています");
    return;
  }

  if (
    type === "response.created" ||
    type === "response.output_item.added"
  ) {
    setStatus("speaking", "話しています", "きずなちゃんがお話ししています");
    return;
  }

  if (
    type === "response.audio.delta" ||
    type === "response.audio_transcript.delta"
  ) {
    setStatus("speaking", "話しています", "きずなちゃんがお話ししています");
  }

  if (
    type === "conversation.item.input_audio_transcription.completed" ||
    type === "input_audio_transcription.completed"
  ) {
    const transcript =
      event.transcript ||
      event.item?.content?.[0]?.transcript ||
      "";

    if (transcript.trim() && transcript.trim() !== lastUserTranscript.trim()) {
      lastUserTranscript = transcript.trim();
      addLog("user", lastUserTranscript);
      await saveRealtimeLog("user", lastUserTranscript);
    }

    return;
  }

  if (
    type === "response.audio_transcript.done" ||
    type === "response.output_audio_transcript.done"
  ) {
    const transcript =
      event.transcript ||
      event.response?.output?.[0]?.content?.[0]?.transcript ||
      "";

    if (transcript.trim() && transcript.trim() !== lastAssistantTranscript.trim()) {
      lastAssistantTranscript = transcript.trim();
      addLog("assistant", lastAssistantTranscript);
      await saveRealtimeLog("assistant", lastAssistantTranscript);
    }

    return;
  }

  if (
    type === "response.done" ||
    type === "response.completed"
  ) {
    const transcript = extractAssistantTranscript(event);

    if (transcript && transcript !== lastAssistantTranscript) {
      lastAssistantTranscript = transcript;
      addLog("assistant", transcript);
      await saveRealtimeLog("assistant", transcript);
    }

    if (connected) {
      setStatus("listening", "聞いています", "続けてお話しください");
    }

    return;
  }

  if (
    type === "session.created" ||
    type === "session.updated"
  ) {
    debug(type, {
      id: event.session?.id,
      model: event.session?.model,
      voice: event.session?.audio?.output?.voice,
    });
    return;
  }

  debug("event", { type });
}

function extractAssistantTranscript(event) {
  const outputs = event.response?.output || [];

  for (const output of outputs) {
    const contents = output.content || [];

    for (const content of contents) {
      if (content.transcript) {
        return content.transcript.trim();
      }

      if (content.text) {
        return content.text.trim();
      }
    }
  }

  return "";
}

async function connectRealtime() {
  try {
    setStatus("connecting");
    debug("connect start");

    connectBtn.disabled = true;
    disconnectBtn.disabled = true;
    muteBtn.disabled = true;

    const token = await getRealtimeToken();
    debug("token ok");

    pc = new RTCPeerConnection();

    pc.addEventListener("connectionstatechange", () => {
      debug("pc connectionstate", {
        state: pc.connectionState,
      });

      if (pc.connectionState === "connected") {
        connected = true;
        setStatus("listening", "聞いています", "そのまま話しかけてください");
      }

      if (
        pc.connectionState === "failed" ||
        pc.connectionState === "disconnected" ||
        pc.connectionState === "closed"
      ) {
        if (connected) {
          setStatus("idle", "待機中", "接続が終了しました");
        }
      }
    });

    pc.addEventListener("iceconnectionstatechange", () => {
      debug("ice state", {
        state: pc.iceConnectionState,
      });
    });

    pc.addEventListener("track", (event) => {
      debug("remote track received");

      if (remoteAudio) {
        remoteAudio.srcObject = event.streams[0];
        remoteAudio.play().catch((error) => {
          debug("remote audio play failed", {
            message: error.message,
          });
        });
      }

      setStatus("speaking", "話しています", "きずなちゃんがお話ししています");
    });

    localStream = await navigator.mediaDevices.getUserMedia({
      audio: {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true,
      },
    });

    debug("microphone ok");

    for (const track of localStream.getAudioTracks()) {
      pc.addTrack(track, localStream);
    }

    const channel = pc.createDataChannel("oai-events");
    setupDataChannel(channel);

    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);

    debug("offer created");

    const sdpResponse = await fetch("https://api.openai.com/v1/realtime/calls", {
      method: "POST",
      body: offer.sdp,
      headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": "application/sdp",
      },
    });

    const answerSdp = await sdpResponse.text();

    if (!sdpResponse.ok) {
      debug("sdp failed", {
        status: sdpResponse.status,
        body: answerSdp.slice(0, 1000),
      });

      throw new Error(`SDP交換に失敗しました: HTTP ${sdpResponse.status}`);
    }

    debug("answer received");

    await pc.setRemoteDescription({
      type: "answer",
      sdp: answerSdp,
    });

    debug("remote description set");

    connected = true;
    muted = false;

    connectBtn.disabled = true;
    disconnectBtn.disabled = false;
    muteBtn.disabled = false;
    muteBtn.textContent = "マイク停止";

    setStatus("listening", "聞いています", "そのまま話しかけてください");
  } catch (error) {
    debug("connect failed", {
      message: error.message,
    });

    addLog("assistant", "Realtime接続に失敗しました。接続ログを確認してください。");
    setStatus("error", "接続失敗", error.message || "Realtime接続に失敗しました");

    cleanup();

    connectBtn.disabled = false;
    disconnectBtn.disabled = true;
    muteBtn.disabled = true;
  }
}

function cleanup() {
  connected = false;
  muted = false;

  stopMouthAnimation();

  if (dc) {
    try {
      dc.close();
    } catch (error) {
      console.warn(error);
    }
    dc = null;
  }

  if (pc) {
    try {
      pc.close();
    } catch (error) {
      console.warn(error);
    }
    pc = null;
  }

  if (localStream) {
    for (const track of localStream.getTracks()) {
      track.stop();
    }
    localStream = null;
  }

  if (remoteAudio) {
    remoteAudio.srcObject = null;
  }
}

function disconnectRealtime() {
  debug("disconnect");

  cleanup();

  connectBtn.disabled = false;
  disconnectBtn.disabled = true;
  muteBtn.disabled = true;
  muteBtn.textContent = "マイク停止";

  setStatus("idle", "待機中", "Realtime会話を終了しました");
}

function toggleMute() {
  if (!localStream) return;

  muted = !muted;

  for (const track of localStream.getAudioTracks()) {
    track.enabled = !muted;
  }

  muteBtn.textContent = muted ? "マイク再開" : "マイク停止";

  setStatus(
    muted ? "idle" : "listening",
    muted ? "マイク停止中" : "聞いています",
    muted ? "マイクを再開すると会話できます" : "そのまま話しかけてください"
  );
}

connectBtn.addEventListener("click", connectRealtime);
disconnectBtn.addEventListener("click", disconnectRealtime);
muteBtn.addEventListener("click", toggleMute);

window.addEventListener("beforeunload", cleanup);

setStatus("idle");
startBlinking();
debug("realtime.js loaded");
