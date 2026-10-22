/**
 * Formats an employee number as it's typed: 000000AAA-0000, then -RH for a
 * rehire (e.g. 260627PAM-0950-RH). Characters that don't fit the next slot are
 * dropped, letters are uppercased, and a dash is added only once the next part
 * starts, so Backspace works naturally. The server checks the same rule
 * (App\Support\EmployeeNumber::PATTERN).
 */
export default function formatEmployeeNumber(raw) {
    const chars = String(raw).toUpperCase().replace(/[^A-Z0-9]/g, '');
    const parts = [
        { length: 6, test: (c) => /[0-9]/.test(c) },
        { length: 3, test: (c) => /[A-Z]/.test(c) },
        { length: 4, test: (c) => /[0-9]/.test(c) },
        { length: 2, test: (c, part) => (part === '' ? c === 'R' : c === 'H') },
    ];

    const done = [];
    let index = 0;

    for (const part of parts) {
        let value = '';

        while (value.length < part.length && index < chars.length) {
            const c = chars[index++];

            if (part.test(c, value)) {
                value += c;
            }
        }

        if (value === '') {
            break;
        }

        done.push(value);

        if (value.length < part.length) {
            break;
        }
    }

    // The date and the province letters run together; the 4 digits and RH follow a dash.
    return [done.slice(0, 2).join(''), ...done.slice(2)].filter((part) => part !== '').join('-');
}
