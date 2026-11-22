// Signage Forge player service worker.
//
// Goal: a screen keeps playing when the network drops, and can even restart
// (power cut, reboot) without network once it has played its content.
//
//  - Media bytes (/api/media/file/…) never change for a given URL, so they are
//    served cache-first. Videos are read with HTTP Range requests: those are
//    answered from the cached full file when we have it.
//  - The player tells us which files its playlist needs ("keep" message): we
//    download the missing ones ahead of time and drop the ones it no longer uses.
//  - The player page and its versioned assets are cached too, so the player
//    can start offline. Everything else (API, dashboard, Mercure) goes to the
//    network as usual.

const MEDIA_CACHE = "signageforge-media-v2";
const SHELL_CACHE = "signageforge-shell-v1";
const KNOWN_CACHES = [MEDIA_CACHE, SHELL_CACHE];

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      for (const name of await caches.keys()) {
        if (name.startsWith("signageforge-") && !KNOWN_CACHES.includes(name)) {
          await caches.delete(name);
        }
      }
      await self.clients.claim();
    })(),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname.startsWith("/api/media/file/")) {
    event.respondWith(media(request));
  } else if (url.pathname.startsWith("/player/")) {
    event.respondWith(networkFirst(request));
  } else if (url.pathname.startsWith("/assets/")) {
    event.respondWith(cacheFirst(request, SHELL_CACHE));
  }
});

self.addEventListener("message", (event) => {
  if (event.data?.type === "keep" && Array.isArray(event.data.urls)) {
    event.waitUntil(keep(event.data.urls));
  }
});

/** Media: cache-first; Range requests are sliced out of the cached file. */
async function media(request) {
  const cache = await caches.open(MEDIA_CACHE);
  const key = new Request(request.url);
  const cached = await cache.match(key);
  const range = request.headers.get("range");

  if (cached) {
    return range ? partial(cached, range) : cached;
  }

  if (range) {
    // Not cached yet: stream this part from the network, and fetch the whole
    // file in the background so the next play (or an outage) is covered.
    download(cache, request.url);
    return fetch(request).catch(() => new Response("", { status: 504 }));
  }

  try {
    const response = await fetch(request);
    if (response.status === 200) await cache.put(key, response.clone());
    return response;
  } catch {
    return new Response("", { status: 504 });
  }
}

/** Builds a 206 answer for "bytes=start-end" out of a full cached response. */
async function partial(response, range) {
  const blob = await response.blob();
  const match = /bytes=(\d*)-(\d*)/.exec(range);
  let start = match && match[1] !== "" ? Number(match[1]) : 0;
  let end = match && match[2] !== "" ? Number(match[2]) : blob.size - 1;
  if (match && match[1] === "" && match[2] !== "") {
    // Suffix range: the last N bytes.
    start = Math.max(0, blob.size - Number(match[2]));
    end = blob.size - 1;
  }
  if (start >= blob.size || end < start) {
    return new Response("", { status: 416, headers: { "Content-Range": `bytes */${blob.size}` } });
  }
  end = Math.min(end, blob.size - 1);

  const headers = new Headers(response.headers);
  headers.set("Content-Range", `bytes ${start}-${end}/${blob.size}`);
  headers.set("Content-Length", String(end - start + 1));
  headers.set("Accept-Ranges", "bytes");
  return new Response(blob.slice(start, end + 1), { status: 206, headers });
}

const downloading = new Map();

/** Downloads a whole media file into the cache, once at a time per URL. */
function download(cache, url) {
  if (!downloading.has(url)) {
    const job = fetch(url)
      .then((response) => (response.status === 200 ? cache.put(url, response) : undefined))
      .catch(() => {})
      .finally(() => downloading.delete(url));
    downloading.set(url, job);
  }
  return downloading.get(url);
}

/** Keeps exactly the files the current playlist needs. */
async function keep(urls) {
  const cache = await caches.open(MEDIA_CACHE);
  const wanted = new Set(urls.map((u) => new URL(u, self.location.origin).href));

  for (const request of await cache.keys()) {
    if (!wanted.has(request.url)) await cache.delete(request);
  }
  for (const url of wanted) {
    if (!(await cache.match(url))) await download(cache, url);
  }
}

/** The player page: fresh when online, last good copy when offline or during maintenance. */
async function networkFirst(request) {
  const cache = await caches.open(SHELL_CACHE);
  try {
    const response = await fetch(request);
    if (response.ok) await cache.put(request, response.clone());
    if (response.status >= 500) return (await cache.match(request)) || response;
    return response;
  } catch {
    return (await cache.match(request)) || new Response("Offline", { status: 503 });
  }
}

/** Versioned assets (their URL changes when their content does). */
async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  if (response.ok) await cache.put(request, response.clone());
  return response;
}
