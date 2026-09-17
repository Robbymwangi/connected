export type Teacher = {
  id: string
  name: string
  initials: string
  email: string
  phone: string
  /* Stream this teacher is class teacher of, if any. */
  homeStream: string | null
  /* Subjects taught, per stream. */
  subjectsByStream: Record<string, string[]>
}

export const teachers: Teacher[] = [
  {
    id: 'u-1',
    name: 'Mr. John Doe',
    initials: 'JD',
    email: 'john.doe@school.edu',
    phone: '+254 700 000 001',
    homeStream: '4W',
    subjectsByStream: { '4W': ['English'], '5A': ['English', 'Maths'] },
  },
  {
    id: 'u-2',
    name: 'Ms. Akinyi',
    initials: 'AK',
    email: 'akinyi@school.edu',
    phone: '+254 700 000 002',
    homeStream: '4E',
    subjectsByStream: { '4E': ['English'], '6A': ['English', 'Science'] },
  },
  {
    id: 'u-4',
    name: 'Ms. Osei',
    initials: 'OS',
    email: 'osei@school.edu',
    phone: '+254 700 000 004',
    homeStream: '5B',
    subjectsByStream: { '5B': ['English', 'Maths'] },
  },
  {
    id: 'u-3',
    name: 'Mr. Kamau',
    initials: 'KM',
    email: 'kamau@school.edu',
    phone: '+254 700 000 003',
    homeStream: null,
    subjectsByStream: { '4W': ['Maths'], '4E': ['Science'], '5A': ['Science'] },
  },
]

export function teachersOfStream(stream: string): Teacher[] {
  return teachers.filter((t) => stream in t.subjectsByStream)
}
