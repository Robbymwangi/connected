const MARK_NAMESPACE = '733181fb-9c96-4898-a85d-69d3d13d83d3'

function bytesFromUuid(uuid: string): Uint8Array {
  const hex = uuid.replaceAll('-', '')
  if (!/^[0-9a-f]{32}$/i.test(hex)) throw new Error('Invalid UUID namespace')
  return Uint8Array.from({ length: 16 }, (_, index) => Number.parseInt(hex.slice(index * 2, index * 2 + 2), 16))
}

function formatUuid(bytes: Uint8Array): string {
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export async function uuidV5(namespace: string, name: string): Promise<string> {
  const namespaceBytes = bytesFromUuid(namespace)
  const nameBytes = new TextEncoder().encode(name)
  const input = new Uint8Array(namespaceBytes.length + nameBytes.length)
  input.set(namespaceBytes)
  input.set(nameBytes, namespaceBytes.length)

  const hash = new Uint8Array(await crypto.subtle.digest('SHA-1', input))
  const uuidBytes = hash.slice(0, 16)
  uuidBytes[6] = (uuidBytes[6] & 0x0f) | 0x50
  uuidBytes[8] = (uuidBytes[8] & 0x3f) | 0x80
  return formatUuid(uuidBytes)
}

export function markIdFor(assessmentId: string, studentId: string, criterionId: string): Promise<string> {
  return uuidV5(MARK_NAMESPACE, `${assessmentId}:${studentId}:${criterionId}`)
}