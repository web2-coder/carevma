// Small, local two-tone chime: no downloaded audio, no backend changes.
// Browser policies require one user gesture before audio can start.
let audioContext = null;
let lastPlayedAt = 0;
const played = new Set();
const SESSION_PREFIX = 'carevma:sound:played:';

function getAudioContext() {
  const Constructor = window.AudioContext || window.webkitAudioContext;
  if (!Constructor) return null;
  if (!audioContext) audioContext = new Constructor();
  return audioContext;
}

export function installNotificationSoundUnlock() {
  if (typeof window === 'undefined') return () => {};
  const unlock = () => {
    try {
      const context = getAudioContext();
      if (context && context.state === 'suspended') context.resume().catch(() => {});
    } catch { /* Audio is optional. */ }
  };
  window.addEventListener('pointerdown', unlock, { passive: true });
  window.addEventListener('keydown', unlock);
  return () => {
    window.removeEventListener('pointerdown', unlock);
    window.removeEventListener('keydown', unlock);
  };
}

export function playNotificationSoundOnce(userId, notificationId) {
  if (typeof window === 'undefined' || !userId || !notificationId) return false;
  const key = `${userId}:${notificationId}`;
  if (played.has(key)) return false;
  try {
    if (window.sessionStorage.getItem(SESSION_PREFIX + key)) return false;
  } catch { /* Restricted storage should not break notifications. */ }
  // Mark before playback, even when the browser blocks autoplay.
  played.add(key);
  if (played.size > 500) played.clear();
  try { window.sessionStorage.setItem(SESSION_PREFIX + key, '1'); } catch {}
  // One chime for a batch of alerts (e.g. reconnect after inactivity).
  if (Date.now() - lastPlayedAt < 900) return false;
  try {
    const context = getAudioContext();
    if (!context || context.state !== 'running') return false;
    const start = context.currentTime + 0.015;
    for (const [frequency, offset] of [[784, 0], [1046.5, 0.12]]) {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0.0001, start + offset);
      gain.gain.exponentialRampToValueAtTime(0.075, start + offset + 0.018);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.19);
      oscillator.connect(gain);
      gain.connect(context.destination);
      oscillator.start(start + offset);
      oscillator.stop(start + offset + 0.2);
    }
    lastPlayedAt = Date.now();
    return true;
  } catch { return false; }
}
