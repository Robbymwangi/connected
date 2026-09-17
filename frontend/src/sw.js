/* ConnectED service worker (ADR 0004). Plain JavaScript, no build step of its own:
   the Vite plugin in build/precache.ts fills in PRECACHE with the build's files and
   VERSION with a hash of that list, then copies this file to the site root.

   Rules, in order:
   1. /api/ is network only, never cached.
   2. Navigations are answered with the cached shell, so a reload offline works.
   3. Files in the precache list are cache-first.
   4. Anything else is network, with a cache fallback if it was ever seen. */

const VERSION = '__VERSION__'
const PRECACHE = __PRECACHE__
const PREFIX = 'connected-'
const CACHE = `${PREFIX}${VERSION}`
const PRECACHED = new Set(PRECACHE)

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)))
  /* Wait for the page to ask (SKIP_WAITING) rather than activating under a
     user who is mid-way through marking. */
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      /* Only our own old versions; other caches on this origin are not ours to drop. */
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith(PREFIX) && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  )
})

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting()
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (url.pathname.startsWith('/api/')) return

  if (request.mode === 'navigate') {
    event.respondWith(caches.match('/index.html').then((hit) => hit ?? fetch(request)))
    return
  }

  /* Rule 3: precached files never change under one version, so the cache answers. */
  if (PRECACHED.has(url.pathname)) {
    event.respondWith(caches.match(request).then((hit) => hit ?? fetch(request)))
    return
  }

  /* Rule 4: anything else is network first, remembered on success, and served from
     the cache only when the network fails. */
  event.respondWith(
    fetch(request)
      .then(async (response) => {
        if (response.ok) {
          const cache = await caches.open(CACHE)
          await cache.put(request, response.clone())
        }
        return response
      })
      .catch(async () => (await caches.match(request)) ?? Response.error()),
  )
})
