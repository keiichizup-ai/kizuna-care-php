const startBtn = document.getElementById("startBtn");
const stopBtn = document.getElementById("stopBtn");
const statusText = document.getElementById("statusText");
const statusSubText = document.getElementById("statusSubText");
const avatar = document.getElementById("avatar");
const log = document.getElementById("log");
const textForm = document.getElementById("textForm");
const textInput = document.getElementById("textInput");
const personId = document.getElementById("personId");
const clearHistoryBtn = document.getElementById("clearHistoryBtn");
const clearAllDataBtn = document.getElementById("clearAllDataBtn");

const SpeechRecognition =
  window.SpeechRecognition || window.webkitSpeechRecognition;

const recognition = SpeechRecognition ? new SpeechRecognition() : null;

let conversationActive = false;
let recognizing = false;
let speaking = false;
let sending = false;
let pendingSend = false;

let mouthTimer = null;
let blinkTimer = null;
let silenceTimer = null;
let currentAudio = null;

let finalTranscript = "";
let interimTranscript = "";
let noSpeechCount = 0;
let cachedJapaneseVoice = null;

// ここを長くすると、ユーザーの話を遮りにくくなります。
// 2500〜3500くらいがおすすめです。
const SILENCE_TO_SEND_MS = 3000;

// trueにすると api/tts.php 経由でOpenAI TTSを試します。
// 失敗した場合は自動でブラウザ標準音声に戻します。
const USE_EXTERNAL_TTS = true;

const statusLabels = {
  idle: {
    label: "待機中",
    sub: "「会話をはじめる」を押してください",
  },
  listening: {
    label: "聞いています",
    sub: "ゆっくりお話しください",
  },
  thinking: {
    label: "考え中",
    sub: "きずなちゃんが返事を考えています",
  },
  speaking: {
    label: "話しています",
    sub: "きずなちゃんがお話ししています",
  },
};

function setStatus(status, label, subLabel) {
  if (!avatar || !statusText || !statusSubText) return;

  const next = statusLabels[status] || statusLabels.idle;

  avatar.dataset.status = status;
  statusText.textContent = label || next.label;
  statusSubText.textContent = subLabel || next.sub;

  if (status !== "speaking") {
    stopMouthAnimation();
  }
}

function addLog(role, content) {
  const item = document.createElement("div");
  item.className = `message ${role}`;
  item.textContent = content;
  log.appendChild(item);
  log.scrollTop = log.scrollHeight;
}

function clearLogView() {
  log.innerHTML = "";
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

  const blink = () => {
    if (!avatar) return;

    avatar.classList.add("is-blinking");

    window.setTimeout(() => {
      avatar.classList.remove("is-blinking");
    }, 140);
  };

  blinkTimer = window.setInterval(() => {
    if (avatar && avatar.dataset.status !== "speaking") {
      blink();
    }
  }, 3200);
}

function stopBlinking() {
  if (blinkTimer) {
    window.clearInterval(blinkTimer);
    blinkTimer = null;
  }
}

function clearSilenceTimer() {
  if (silenceTimer) {
    window.clearTimeout(silenceTimer);
    silenceTimer = null;
  }
}

function getCurrentTranscript() {
  return `${finalTranscript} ${interimTranscript}`.trim();
}

function resetTranscript() {
  finalTranscript = "";
  interimTranscript = "";
}

function scheduleAutoSend() {
  clearSilenceTimer();

  silenceTimer = window.setTimeout(() => {
    const text = getCurrentTranscript();

    if (!text) {
      return;
    }

    pendingSend = true;
    resetTranscript();

    if (recognizing) {
      stopListening();
    }

    sendMessage(text);
  }, SILENCE_TO_SEND_MS);
}

function pickJapaneseVoice() {
  if (!("speechSynthesis" in window)) return null;

  const voices = window.speechSynthesis.getVoices();
  if (!voices || voices.length === 0) return null;

  // Mac/Chrome/Windowsで出やすい日本語音声を優先します。
  cachedJapaneseVoice =
    voices.find((voice) => voice.lang === "ja-JP" && /Kyoko|Otoya|Google|Microsoft|Nanami|Haruka|Ichiro/i.test(voice.name)) ||
    voices.find((voice) => voice.lang === "ja-JP") ||
    voices.find((voice) => voice.lang && voice.lang.startsWith("ja")) ||
    null;

  return cachedJapaneseVoice;
}

if ("speechSynthesis" in window) {
  window.speechSynthesis.onvoiceschanged = () => {
    pickJapaneseVoice();
  };
  pickJapaneseVoice();
}

function stopCurrentAudio() {
  if (currentAudio) {
    try {
      currentAudio.pause();
      currentAudio.src = "";
    } catch (error) {
      console.warn(error);
    }
    currentAudio = null;
  }
}

function playExternalTts(text) {
  return new Promise(async (resolve) => {
    if (!USE_EXTERNAL_TTS) {
      resolve(false);
      return;
    }

    try {
      const response = await fetch("api/tts.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ text }),
      });

      const contentType = response.headers.get("Content-Type") || "";

      if (!response.ok || !contentType.includes("audio")) {
        resolve(false);
        return;
      }

      const blob = await response.blob();
      const audioUrl = URL.createObjectURL(blob);
      const audio = new Audio(audioUrl);

      currentAudio = audio;
      speaking = true;
      setStatus("speaking");
      startMouthAnimation();

      audio.onended = () => {
        speaking = false;
        stopMouthAnimation();
        URL.revokeObjectURL(audioUrl);
        currentAudio = null;
        resolve(true);
      };

      audio.onerror = () => {
        speaking = false;
        stopMouthAnimation();
        URL.revokeObjectURL(audioUrl);
        currentAudio = null;
        resolve(false);
      };

      await audio.play();
    } catch (error) {
      console.warn("外部TTSに失敗したため、ブラウザ音声に戻します。", error);
      speaking = false;
      stopMouthAnimation();
      resolve(false);
    }
  });
}

function speakWithBrowser(text) {
  return new Promise((resolve) => {
    if (!("speechSynthesis" in window)) {
      resolve();
      return;
    }

    window.speechSynthesis.cancel();
    stopMouthAnimation();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = "ja-JP";
    utterance.rate = 0.88;
    utterance.pitch = 1.08;
    utterance.volume = 1;

    const voice = cachedJapaneseVoice || pickJapaneseVoice();
    if (voice) {
      utterance.voice = voice;
    }

    utterance.onstart = () => {
      speaking = true;
      setStatus("speaking");
      startMouthAnimation();
    };

    utterance.onend = () => {
      speaking = false;
      stopMouthAnimation();
      resolve();
    };

    utterance.onerror = () => {
      speaking = false;
      stopMouthAnimation();
      resolve();
    };

    window.speechSynthesis.speak(utterance);
  });
}

async function speak(text) {
  stopCurrentAudio();

  if ("speechSynthesis" in window) {
    window.speechSynthesis.cancel();
  }

  const externalPlayed = await playExternalTts(text);
  if (externalPlayed) {
    return;
  }

  await speakWithBrowser(text);
}

async function sendMessage(message) {
  const trimmed = message.trim();

  if (trimmed === "") {
    pendingSend = false;
    return;
  }

  sending = true;
  clearSilenceTimer();

  addLog("user", trimmed);
  setStatus("thinking");

  try {
    const controller = new AbortController();

    const timeoutId = window.setTimeout(() => {
      controller.abort();
    }, 25000);

    const response = await fetch("api/chat.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        person_id: Number(personId.value),
        message: trimmed,
      }),
      signal: controller.signal,
    });

    window.clearTimeout(timeoutId);

    const data = await response.json();

    if (!data.ok) {
      throw new Error(data.error || "エラーが発生しました。");
    }

    const reply =
      data.reply || "ごめんなさい、もう一度ゆっくり教えてください。";

    noSpeechCount = 0;

    addLog("assistant", reply);
    await speak(reply);
  } catch (error) {
    const fallback = "ごめんなさい、もう一度ゆっくり教えてください。";

    addLog("assistant", fallback);
    console.error(error);

    await speak(fallback);
  } finally {
    sending = false;
    pendingSend = false;

    if (conversationActive) {
      window.setTimeout(startListening, 650);
    } else {
      setStatus("idle");
    }
  }
}

function startListening() {
  if (!recognition) return;
  if (recognizing || speaking || sending || pendingSend || !conversationActive) return;

  try {
    recognition.start();
  } catch (error) {
    console.warn(error);
  }
}

function stopListening() {
  if (!recognition || !recognizing) return;

  try {
    recognition.stop();
  } catch (error) {
    console.warn(error);
  }
}

if (recognition) {
  recognition.lang = "ja-JP";

  // 長い話を拾いやすくするため、途中結果も取得します。
  recognition.interimResults = true;

  // Chrome側で途中終了しにくくするため true にします。
  recognition.continuous = true;

  recognition.onstart = () => {
    recognizing = true;
    setStatus("listening");
  };

  recognition.onresult = (event) => {
    let interim = "";

    for (let i = event.resultIndex; i < event.results.length; i++) {
      const transcript = event.results[i][0].transcript;

      if (event.results[i].isFinal) {
        finalTranscript += transcript;
      } else {
        interim += transcript;
      }
    }

    interimTranscript = interim;
    noSpeechCount = 0;

    const preview = getCurrentTranscript();

    if (preview) {
      setStatus("listening", "聞いています", "続けてお話しください");
      scheduleAutoSend();
    }
  };

  recognition.onend = () => {
    recognizing = false;

    if (!conversationActive || speaking || sending || pendingSend) {
      return;
    }

    const text = getCurrentTranscript();

    if (text) {
      setStatus("listening", "聞いています", "続きがあればお話しください");

      // Chromeが途中で認識を止めた場合でも、会話中なら再開します。
      window.setTimeout(() => {
        if (
          conversationActive &&
          !recognizing &&
          !speaking &&
          !sending &&
          !pendingSend
        ) {
          startListening();
        }
      }, 250);

      return;
    }

    setStatus("idle", "待機中", "次のお話を待っています");

    window.setTimeout(() => {
      if (conversationActive && !recognizing && !speaking && !sending) {
        startListening();
      }
    }, 700);
  };

  recognition.onerror = (event) => {
    recognizing = false;
    console.warn(event.error);

    if (!conversationActive) {
      return;
    }

    if (event.error === "no-speech") {
      noSpeechCount += 1;

      setStatus("listening", "聞いています", "声が入るのを待っています");

      if (noSpeechCount >= 2) {
        noSpeechCount = 0;

        const message = "聞こえていますよ。ゆっくりお話しください。";
        addLog("assistant", message);

        speak(message).then(() => {
          if (conversationActive) {
            window.setTimeout(startListening, 700);
          }
        });

        return;
      }

      window.setTimeout(startListening, 700);
      return;
    }

    const message = "すみません、もう一度ゆっくりお願いします。";
    addLog("assistant", message);

    speak(message).then(() => {
      if (conversationActive) {
        window.setTimeout(startListening, 700);
      }
    });
  };
} else {
  addLog(
    "assistant",
    "このブラウザは音声認識に対応していません。文字入力を使ってください。"
  );
}

async function clearConversationData(mode) {
  const isAll = mode === "all";
  const label = isAll ? "会話履歴とサマリ" : "会話履歴";

  const ok = window.confirm(
    `選択中の会話者の${label}を削除します。\nこの操作は元に戻せません。よろしいですか？`
  );

  if (!ok) return;

  const wasActive = conversationActive;
  conversationActive = false;
  clearSilenceTimer();
  resetTranscript();
  stopListening();
  stopCurrentAudio();
  window.speechSynthesis?.cancel();
  speaking = false;
  sending = false;
  pendingSend = false;
  stopMouthAnimation();
  setStatus("thinking", "削除中", "少しお待ちください");

  try {
    const response = await fetch("api/clear_history.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        person_id: Number(personId.value),
        mode,
      }),
    });

    const data = await response.json();

    if (!data.ok) {
      throw new Error(data.error || "削除に失敗しました。");
    }

    clearLogView();
    addLog("assistant", `${label}を削除しました。`);
    setStatus("idle", "削除しました", "必要ならまた会話をはじめてください");
  } catch (error) {
    console.error(error);
    addLog("assistant", "すみません、削除に失敗しました。");
    setStatus("idle", "待機中", "削除に失敗しました");
  } finally {
    startBtn.disabled = false;
    stopBtn.disabled = true;
    conversationActive = false;

    // 削除前に会話中だった場合も、勝手に再開しないようにします。
    if (wasActive) {
      addLog("assistant", "続ける場合は、もう一度『会話をはじめる』を押してください。");
    }
  }
}

startBtn.addEventListener("click", () => {
  conversationActive = true;
  startBtn.disabled = true;
  stopBtn.disabled = false;

  clearSilenceTimer();
  resetTranscript();
  pendingSend = false;
  sending = false;
  noSpeechCount = 0;

  addLog("assistant", "こんにちは。お話ししましょう。");

  speak("こんにちは。お話ししましょう。").then(() => {
    if (conversationActive) {
      startListening();
    }
  });
});

stopBtn.addEventListener("click", () => {
  conversationActive = false;
  startBtn.disabled = false;
  stopBtn.disabled = true;

  clearSilenceTimer();
  resetTranscript();
  pendingSend = false;
  sending = false;
  noSpeechCount = 0;

  stopListening();
  stopCurrentAudio();
  window.speechSynthesis?.cancel();

  speaking = false;
  stopMouthAnimation();
  setStatus("idle");
});

textForm.addEventListener("submit", (event) => {
  event.preventDefault();

  const value = textInput.value;
  textInput.value = "";

  sendMessage(value);
});

if (clearHistoryBtn) {
  clearHistoryBtn.addEventListener("click", () => {
    clearConversationData("messages");
  });
}

if (clearAllDataBtn) {
  clearAllDataBtn.addEventListener("click", () => {
    clearConversationData("all");
  });
}

window.addEventListener("beforeunload", () => {
  clearSilenceTimer();
  stopBlinking();
  stopMouthAnimation();
  stopCurrentAudio();
  window.speechSynthesis?.cancel();
});

setStatus("idle");
startBlinking();
