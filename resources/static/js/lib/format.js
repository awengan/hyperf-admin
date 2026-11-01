/**
 * Pure date / time helpers shared by the date and time fields.
 *
 * Everything here is dependency free so it can be unit tested in Node.
 */

export const RANGE_DATE_TYPES = [
  'daterange',
  'datetimerange',
  'monthrange',
  'yearrange',
  'quarterrange',
]

/**
 * Map an Element UI picker type onto the closest Naive UI `n-date-picker` type.
 */
export function naiveDateType(dateType) {
  switch (dateType) {
    case 'week':
    // Naive UI has no multi-date picker; a single date is the closest match.
    case 'dates':
      return 'date'
    case 'months':
      return 'month'
    case 'years':
      return 'year'
    case 'monthrange':
      return 'monthrange'
    case 'yearrange':
      return 'yearrange'
    case 'quarterrange':
      return 'quarterrange'
    case 'datetimerange':
      return 'datetimerange'
    case 'daterange':
      return 'daterange'
    case 'datetime':
      return 'datetime'
    case 'month':
      return 'month'
    case 'year':
      return 'year'
    case 'quarter':
      return 'quarter'
    default:
      return 'date'
  }
}

export function isRangeType(dateType) {
  return RANGE_DATE_TYPES.includes(naiveDateType(dateType))
}

/**
 * Format string understood by Naive UI (and Element UI, the tokens match).
 */
export function formatForType(dateType) {
  switch (naiveDateType(dateType)) {
    case 'year':
    case 'yearrange':
      return 'yyyy'
    case 'quarter':
    case 'quarterrange':
      return 'yyyy-qq'
    case 'month':
    case 'monthrange':
      return 'yyyy-MM'
    case 'datetime':
    case 'datetimerange':
      return 'yyyy-MM-dd HH:mm:ss'
    default:
      return 'yyyy-MM-dd'
  }
}

/**
 * Convert the PHP `shortcuts` list into the object Naive UI expects.
 *
 * PHP shape (single): `[{ text, time }]`
 * PHP shape (range):  `[{ text, start, end }]`
 *
 * `'today'` means "now"; other values are millisecond timestamps.
 */
export function toShortcuts(shortcuts, dateType, now = Date.now()) {
  if (!Array.isArray(shortcuts) || shortcuts.length === 0) {
    return undefined
  }

  const range = isRangeType(dateType)
  const result = {}

  for (const shortcut of shortcuts) {
    if (!shortcut || typeof shortcut.text !== 'string' || shortcut.text === '') {
      continue
    }

    if (range) {
      result[shortcut.text] = () => [
        resolveTimestamp(shortcut.start, now),
        resolveTimestamp(shortcut.end, now),
      ]
    } else {
      result[shortcut.text] = () => resolveTimestamp(shortcut.time, now)
    }
  }

  return Object.keys(result).length === 0 ? undefined : result
}

export function resolveTimestamp(value, now = Date.now()) {
  if (value === undefined || value === null || value === 'today' || value === '') {
    return now
  }

  const numeric = Number(value)

  return Number.isFinite(numeric) ? numeric : now
}

/**
 * Read an optional millisecond bound.
 *
 * `Number(null)` is `0`, which would silently lock the picker at the epoch, so
 * empty values are rejected explicitly.
 */
function toBound(value) {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const numeric = Number(value)

  return Number.isFinite(numeric) ? numeric : null
}

/**
 * Build the `isDateDisabled` callback from the PHP deadline fields.
 */
export function toDateDisabled(field, dateType) {
  const earliest = toBound(field?.disabledDateByEarliest)
  const deadline = toBound(field?.disabledDateByDeadline)

  if (earliest === null && deadline === null) {
    return undefined
  }

  // Range pickers receive (timestamp, position, value), single ones
  // (timestamp, detail); only the timestamp matters here.
  return (timestamp) =>
    (earliest !== null && timestamp < earliest) || (deadline !== null && timestamp > deadline)
}

/**
 * `defaultTime` of `n-date-picker` (a `HH:mm:ss` string, or a pair for ranges).
 */
export function toDefaultTime(field, dateType) {
  if (isRangeType(dateType)) {
    const start = field?.startDefaultTime
    const end = field?.endDefaultTime

    if (typeof start === 'string' && typeof end === 'string') {
      return [start, end]
    }

    if (typeof start === 'string') {
      return [start, undefined]
    }

    if (typeof end === 'string') {
      return [undefined, end]
    }

    return undefined
  }

  return typeof field?.defaultTime === 'string' ? field.defaultTime : undefined
}

/* ----------------------------------------------------------------- time --- */

export function pad2(value) {
  return String(value).padStart(2, '0')
}

export function parseTimeOfDay(value) {
  const match = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(String(value ?? '').trim())

  if (!match) {
    return null
  }

  const hours = Number(match[1])
  const minutes = Number(match[2])
  const seconds = match[3] === undefined ? 0 : Number(match[3])

  if (hours > 23 || minutes > 59 || seconds > 59) {
    return null
  }

  return { hours, minutes, seconds, total: hours * 3600 + minutes * 60 + seconds }
}

/**
 * Generate the options of a fixed interval time list (Element's
 * `el-time-select`), which Naive UI does not provide.
 */
export function timeSelectOptions(start, end, step) {
  const from = parseTimeOfDay(start) ?? parseTimeOfDay('00:00') ?? { total: 0 }
  const to = parseTimeOfDay(end) ?? parseTimeOfDay('23:59') ?? { total: 86340 }
  const interval = parseTimeOfDay(step) ?? { total: 1800 }
  const stride = Math.max(60, interval.total)

  const options = []

  for (let total = from.total; total <= to.total; total += stride) {
    const hours = Math.floor(total / 3600) % 24
    const minutes = Math.floor((total % 3600) / 60)
    const seconds = total % 60
    const label = `${pad2(hours)}:${pad2(minutes)}${seconds ? ':' + pad2(seconds) : ''}`

    options.push({ label, value: label })
  }

  return options
}

/**
 * Parse `'18:00:00 - 23:00:00'` into a list of `{ start, end }` windows in
 * seconds of the day.
 */
export function parseSelectableRange(input) {
  if (typeof input !== 'string' || input.trim() === '') {
    return []
  }

  return input
    .split(',')
    .map((chunk) => chunk.split('-').map((part) => parseTimeOfDay(part)))
    .filter(([from, to]) => from !== null && to !== null)
    .map(([from, to]) => ({ start: from.total, end: to.total }))
}

/**
 * `isHourDisabled` for `n-time-picker`, derived from `selectableRange`.
 */
export function toHourDisabled(selectableRange) {
  const windows = parseSelectableRange(selectableRange)

  if (windows.length === 0) {
    return undefined
  }

  return (hour) => !windows.some(({ start, end }) => hour * 3600 >= start && hour * 3600 <= end)
}
