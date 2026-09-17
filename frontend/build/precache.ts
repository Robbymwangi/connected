import { createHash } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { Plugin } from 'vite'

/* Emits sw.js at the site root with the build's files as its precache list
   (ADR 0004). The list is everything Vite wrote plus the fixed files in public/,
   so a new font or chunk is cached without anyone remembering to add it. */

const PUBLIC_FILES = ['/', '/index.html', '/manifest.webmanifest', '/favicon.svg', '/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon.svg']

export function precache(): Plugin {
  return {
    name: 'connected-precache',
    apply: 'build',
    generateBundle(_options, bundle) {
      const built = Object.keys(bundle)
        .filter((file) => file !== 'index.html' && !file.endsWith('.map'))
        .map((file) => `/${file}`)
      const list = [...PUBLIC_FILES, ...built].sort()

      /* The version hashes contents as well as paths, so a changed manifest, icon,
         or shell yields a new cache even when the list of URLs is unchanged. Built
         files carry a content hash in their names already; public files do not. */
      const hash = createHash('sha256')
      for (const file of list) hash.update(file + '\n')
      for (const [name, item] of Object.entries(bundle)) {
        hash.update(name + '\n')
        hash.update(item.type === 'chunk' ? item.code : typeof item.source === 'string' ? item.source : Buffer.from(item.source))
      }
      const publicDir = resolve(import.meta.dirname, '../public')
      for (const file of PUBLIC_FILES) {
        const onDisk = resolve(publicDir, '.' + (file === '/' ? '/index.html' : file))
        if (existsSync(onDisk)) hash.update(readFileSync(onDisk))
      }
      const version = hash.digest('hex').slice(0, 12)
      const source = readFileSync(resolve(import.meta.dirname, '../src/sw.js'), 'utf8')
        .replace('__VERSION__', version)
        .replace('__PRECACHE__', JSON.stringify(list, null, 2))
      this.emitFile({ type: 'asset', fileName: 'sw.js', source })
      this.info(`precache: ${list.length} files, version ${version}`)
    },
  }
}
