export type Student = {
  id: string
  classId: string
  name: string
  gender: 'F' | 'M'
  dob: string
}

/* Grade 4W roster. */
export const students: Student[] = [
  { id: 's1', classId: 'class-4w', name: 'Wanjiku Njoroge', gender: 'F', dob: '2016-03-12' },
  { id: 's2', classId: 'class-4w', name: 'Kofi Mensah', gender: 'M', dob: '2016-07-04' },
  { id: 's3', classId: 'class-4w', name: 'Amina Osei', gender: 'F', dob: '2016-01-19' },
  { id: 's4', classId: 'class-4w', name: 'Liam Osei', gender: 'M', dob: '2015-08-28' },
  { id: 's5', classId: 'class-4w', name: 'Fatou Diallo', gender: 'F', dob: '2015-11-03' },
  { id: 's6', classId: 'class-4w', name: 'Amara Kamau', gender: 'M', dob: '2016-02-15' },
  { id: 's7', classId: 'class-4w', name: 'Nia Mensah', gender: 'F', dob: '2016-06-22' },
  { id: 's8', classId: 'class-4w', name: 'Seun Adeyemi', gender: 'M', dob: '2016-04-09' },
]

export function rosterFor(classId: string): Student[] {
  return students.filter((s) => s.classId === classId)
}

export function initials(name: string): string {
  return name
    .split(' ')
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
