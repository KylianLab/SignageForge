// Signage Forge player service worker.
// Goal: once a media file has been fetched successfully, keep playing it even
// if the network later drops. Strategy: stale-while-revalidate for media bytes,
// network-only for everything else (API, HTML).

const MEDIA_CACHE = "signageforge-media-v1";

self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));

self.addEventListener("fetch", (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Only handle GET requests for media bytes from our own origin.
  const isMedia =
    request.method === "GET" &&
    url.origin === self.location.origin &&
    url.pathname.startsWith("/api/media/file/");

  if (!isMedia) return; // let the network handle it normally

  // Range requests (video scrubbing) return 206 and must not be cached as-is.
  if (request.headers.has("range")) return;

  event.respondWith(
    caches.open(MEDIA_CACHE).then(async (cache) => {
      const cached = await cache.match(request);
      const network = fetch(request)
        .then((res) => {
          if (res.ok && res.status === 200) cache.put(request, res.clone());
          return res;
        })
        .catch(() => null);

      // Serve cache immediately if we have it; otherwise wait for the network.
      return cached || (await network) || new Response("", { status: 504 });
    }),
  );
});
