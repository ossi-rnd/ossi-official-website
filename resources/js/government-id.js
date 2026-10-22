/**
 * Formats a government ID as it's typed, in the agency's pattern: only digits
 * are kept, and a dash is added once the next group starts, so Backspace works
 * naturally. groups is the pattern, e.g. [2, 7, 1] for SSS (00-0000000-0).
 * The server checks and saves the same way (App\Support\GovernmentId).
 */
export default function formatGovernmentId(raw, groups) {
    let digits = String(raw).replace(/\D/g, '').slice(0, groups.reduce((sum, size) => sum + size, 0));
    const parts = [];

    for (const size of groups) {
        if (digits === '') {
            break;
        }

        parts.push(digits.slice(0, size));
        digits = digits.slice(size);
    }

    return parts.join('-');
}
