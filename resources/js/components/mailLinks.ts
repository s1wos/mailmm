function trimSentencePunctuation(candidate: string): string {
    if (!/[.,;:!?'…)\]}]$/u.test(candidate)) return candidate
    // A closing bracket belongs to the URL when it pairs with one inside the URL.
    const opening: Record<string, string> = { '(': ')', '[': ']', '{': '}' }
    const balance: Record<string, number> = { ')': 0, ']': 0, '}': 0 }
    for (const char of candidate) {
        if (opening[char]) balance[opening[char]]++
        else if (char in balance) balance[char]--
    }
    let end = candidate.length
    while (end > 0) {
        const last = candidate[end - 1]
        if (balance[last] < 0) balance[last]++
        else if (!/[.,;:!?'…]/u.test(last)) break
        end--
    }
    return candidate.slice(0, end)
}

export function splitMailLinks(text: string): { text: string, href?: string }[] {
    const result: { text: string, href?: string }[] = []
    const candidates = /(?<![\p{L}\p{N}_])https?:\/\/(?![/?#])[^\s<>"«»“”‘’]+/giu
    let cursor = 0
    for (const match of text.matchAll(candidates)) {
        const url = trimSentencePunctuation(match[0])
        if (/[\\\u0000-\u001f\u007f]/u.test(url) || !URL.canParse(url)) continue
        if (match.index > cursor) result.push({ text: text.slice(cursor, match.index) })
        result.push({ text: url, href: url })
        cursor = match.index + url.length
    }
    if (cursor < text.length) result.push({ text: text.slice(cursor) })
    return result
}
