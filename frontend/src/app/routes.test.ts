import { describe, expect, it } from 'vitest'
import type { Location } from './location'
import { parseLocation, pathFor } from './routes'

const cases: Array<[Location, string]> = [
  [{ screen: 'dashboard' }, '/'],
  [{ screen: 'assessments' }, '/assessments'],
  [{ screen: 'assessments', creating: true }, '/assessments?new'],
  [{ screen: 'assessments', assessmentId: 'a1', view: 'grid' }, '/assessments/a1/grid'],
  [{ screen: 'assessments', assessmentId: 'a1', view: 'report' }, '/assessments/a1/report'],
  [{ screen: 'classes' }, '/classes'],
  [{ screen: 'classes', classId: 'class-4w' }, '/classes/class-4w'],
  [{ screen: 'classes', classId: 'class-4w', studentId: 's1' }, '/classes/class-4w/students/s1'],
  [{ screen: 'classes', teacherId: 'u-2' }, '/teachers/u-2'],
  [{ screen: 'sync' }, '/sync'],
  [{ screen: 'sync', highlight: 'c-1' }, '/sync?highlight=c-1'],
  [{ screen: 'reports' }, '/reports'],
]

describe('pathFor and parseLocation', () => {
  it.each(cases)('round-trips %j as %s', (loc, path) => {
    expect(pathFor(loc)).toBe(path)
    const [pathname, search] = path.split('?')
    expect(parseLocation(pathname, search ? `?${search}` : '')).toEqual(loc)
  })
  it('sends unknown paths to the dashboard', () => {
    expect(parseLocation('/nothing/here')).toEqual({ screen: 'dashboard' })
  })
  it('sends a malformed path under a known screen to that screen\'s list', () => {
    expect(parseLocation('/assessments/a1')).toEqual({ screen: 'assessments' })
    expect(parseLocation('/assessments/a1/edit')).toEqual({ screen: 'assessments' })
    expect(parseLocation('/classes/class-4w/students')).toEqual({ screen: 'classes', classId: 'class-4w' })
    expect(parseLocation('/teachers')).toEqual({ screen: 'classes' })
  })
  it('encodes ids that need it and decodes them back', () => {
    const loc: Location = { screen: 'classes', classId: 'a b', studentId: 'x/y' }
    expect(pathFor(loc)).toBe('/classes/a%20b/students/x%2Fy')
    expect(parseLocation(pathFor(loc))).toEqual(loc)
  })
  it('ignores a trailing slash', () => {
    expect(parseLocation('/reports/')).toEqual({ screen: 'reports' })
  })
})
