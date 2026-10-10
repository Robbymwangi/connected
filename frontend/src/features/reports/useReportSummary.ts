import { useEffect, useRef, useState } from 'react'
import type { ReportSummaryFilters, ReportSummaryResponse } from '../../api/reports'
import { getReportSummary } from '../../api/reports'
import { useConnectivity } from '../../lib/connectivity'

type FetchState = {
  key: string
  response: ReportSummaryResponse | null
  loading: boolean
  error: string | null
}

const EMPTY_STATE: FetchState = { key: '', response: null, loading: false, error: null }

export function useReportSummary(filters: ReportSummaryFilters | null, token: string) {
  const { isOnline } = useConnectivity()
  const [state, setState] = useState<FetchState>(EMPTY_STATE)
  const [attempt, setAttempt] = useState(0)
  const completed = useRef<{ key: string; attempt: number } | null>(null)
  const stream = filters?.stream
  const year = filters?.year
  const subjectId = filters?.subjectId ?? null
  const term = filters?.term ?? null
  const assessmentName = filters?.assessmentName ?? null
  const key = stream && year ? JSON.stringify([stream, year, subjectId, term, assessmentName]) : ''

  useEffect(() => {
    if (!stream || !year || !key || !isOnline) return
    if (completed.current?.key === key && completed.current.attempt === attempt) return

    let cancelled = false
    setState((current) => ({
      key,
      response: current.key === key ? current.response : null,
      loading: true,
      error: null,
    }))

    void getReportSummary({ stream, year, subjectId, term, assessmentName }, token).then((response) => {
      if (cancelled) return
      completed.current = { key, attempt }
      setState({ key, response, loading: false, error: null })
    }).catch((error: unknown) => {
      if (cancelled) return
      setState((current) => ({
        key,
        response: current.key === key ? current.response : null,
        loading: false,
        error: error instanceof Error ? error.message : 'Could not load this report.',
      }))
    })

    return () => {
      cancelled = true
    }
  }, [attempt, isOnline, key, stream, year, subjectId, term, assessmentName, token])

  const current = state.key === key ? state : null

  return {
    response: current?.response ?? null,
    error: current?.error ?? null,
    isLoading: !!filters && isOnline && (current?.loading ?? true),
    isOnline,
    retry: () => setAttempt((value) => value + 1),
  }
}