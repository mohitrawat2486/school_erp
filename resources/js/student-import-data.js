export function parseClipboard(text, {maxRows = 2000, maxColumns = 40, maxBytes = 2000000} = {}) {
    if (!text.trim()) throw new Error('Paste student data first.');
    if (new TextEncoder().encode(text).length > maxBytes) throw new Error('Paste at most 2 MB of data at a time.');
    const rows = []; let row = [], value = '', quoted = false;
    const cell = () => { row.push(value.trim()); value = ''; };
    const line = () => { cell(); rows.push(row); row = []; };
    for (let i = 0; i < text.length; i++) {
        const char = text[i];
        if (quoted) {
            if (char === '"' && text[i + 1] === '"') { value += '"'; i++; }
            else if (char === '"') quoted = false;
            else value += char;
        } else if (char === '"' && value === '') quoted = true;
        else if (char === '\t') cell();
        else if (char === '\n' || char === '\r') { if (char === '\r' && text[i + 1] === '\n') i++; line(); }
        else value += char;
    }
    if (quoted) throw new Error('An opening quote has no closing quote. Check the pasted cells.');
    line();
    while (rows.length && rows.at(-1).every(cell => cell === '')) rows.pop();
    if (!rows.length) throw new Error('Paste student data first.');
    if (rows.length > maxRows + 1) throw new Error(`Paste at most ${maxRows} student rows plus a header. Split larger sheets into smaller imports.`);
    if (rows.some(row => row.length > maxColumns)) throw new Error(`Paste at most ${maxColumns} columns at a time.`);
    return rows;
}

const headerKey = value => value.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');
export function autoMap(headers, fields) {
    const aliases = new Map();
    for (const [field, config] of Object.entries(fields)) for (const alias of config.aliases) {
        const key = headerKey(alias);
        aliases.set(key, aliases.has(key) && aliases.get(key) !== field ? null : field);
    }
    const used = new Set();
    return headers.map(header => {
        const field = aliases.get(headerKey(header));
        if (!field || used.has(field) || (field === 'student_name' && ['first_name', 'middle_name', 'last_name'].some(name => used.has(name))) || (used.has('student_name') && ['first_name', 'middle_name', 'last_name'].includes(field))) return null;
        used.add(field); return field;
    });
}

export function mappingErrors(mappings, settings) {
    const fields = mappings.filter(Boolean), errors = [];
    if (!fields.length) errors.push('Map at least one Student field.');
    if (new Set(fields).size !== fields.length) errors.push('Each Student field can only be mapped once.');
    if (fields.includes('student_name') && fields.some(field => ['first_name', 'middle_name', 'last_name'].includes(field))) errors.push('Use full name or separate name columns, not both.');
    if (!fields.includes('admission_number')) errors.push('Map Admission number.');
    if (!fields.includes('aadhaar_number')) errors.push('Map Aadhaar number.');
    if (!fields.includes('first_name') && !fields.includes('student_name')) errors.push('Map Student full name or First name.');
    if (!fields.includes('admission_date') && !settings.admission_date) errors.push('Map Admission date or choose a default admission date.');
    return errors;
}

export function localValidation(rows, mappings, fields) {
    return rows.map((cells, index) => {
        const result = {row: index + 1, fields: {}, values: {}, status: 'valid', messages: []};
        mappings.forEach((field, column) => {
            if (!field) return;
            const value = cells[column] || '';
            const invalid = (fields[field].required && !value) || (field === 'first_name' && !value) || (field === 'student_name' && !value) || (field === 'aadhaar_number' && !/^[0-9]{12}$/.test(value));
            result.fields[field] = {status: invalid ? 'invalid' : value ? 'valid' : 'warning', messages: invalid ? ['Review this required value.'] : value ? [] : ['Empty optional value.'], normalizedValue: value};
            result.values[field] = value;
        });
        if (cells.length !== mappings.length) result.messages.push('Inconsistent column count.');
        const statuses = Object.values(result.fields).map(cell => cell.status);
        result.status = result.messages.length || statuses.includes('invalid') ? 'invalid' : statuses.includes('warning') ? 'warning' : 'valid';
        return result;
    });
}

export function summary(rows) {
    return rows.reduce((result, row) => { result[row.status]++; return result; }, {total: rows.length, valid: 0, warning: 0, invalid: 0});
}
