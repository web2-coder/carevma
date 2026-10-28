import Pusher from 'pusher-js';

let client = null;

// The <meta> token is captured at page load and goes stale once Laravel rotates the
// session token at login. Read the XSRF-TOKEN cookie instead, fresh per auth call.
function xsrfToken() {
  const raw = document.cookie
    .split('; ')
    .find(cookie => cookie.startsWith('XSRF-TOKEN='));

  return raw ? decodeURIComponent(raw.slice('XSRF-TOKEN='.length)) : '';
}

export function getPusherClient() {
  const key = import.meta.env.VITE_PUSHER_APP_KEY || 'a7353eddabf5431ea145';
  const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER || 'ap2';

  if (!key) return null;
  if (client) return client;

  Pusher.logToConsole = import.meta.env.DEV && import.meta.env.VITE_PUSHER_DEBUG === 'true';

  client = new Pusher(key, {
    cluster,
    forceTLS: true,
    channelAuthorization: {
      endpoint: '/broadcasting/auth',
      transport: 'ajax',
      headersProvider: () => ({
        'X-XSRF-TOKEN': xsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      }),
    },
  });

  return client;
}

export function subscribeToCareVmaNotifications(userId, callback) {
  if (!userId) return () => {};

  const pusher = getPusherClient();
  if (!pusher) return () => {};

  const channelName = `private-carevma.user.${userId}`;
  const channel = pusher.subscribe(channelName);
  const eventName = 'carevma.notification';
  const handler = payload => callback?.(payload);

  channel.bind(eventName, handler);

  return () => {
    channel.unbind(eventName, handler);
    pusher.unsubscribe(channelName);
  };
}
