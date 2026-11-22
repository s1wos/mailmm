const timestamp = new Intl.DateTimeFormat('ru-RU', {
    timeZone: 'UTC',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
})
const number = new Intl.NumberFormat('ru-RU')

export function formatTimestamp(value: string) {
    return timestamp.format(new Date(value))
}

export function formatCount(value: number) {
    return number.format(value)
}
