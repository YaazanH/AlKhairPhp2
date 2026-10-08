export function groupOptionColumns(serializedColumns, phone) {
    const columns = JSON.parse(serializedColumns);
    return phone ? columns.slice(0, 1) : columns;
}

export function containedGroupMenu(trigger, bounds, desiredHeight = 240) {
    const gap = 6;
    const below = Math.max(0, bounds.bottom - trigger.bottom - gap);
    const above = Math.max(0, trigger.top - bounds.top - gap);
    const openAbove = below < desiredHeight && above > below;
    const width = Math.max(0, Math.min(trigger.width, bounds.right - bounds.left));
    return {
        openAbove,
        height: Math.min(desiredHeight, openAbove ? above : below),
        width,
        left: Math.max(bounds.left, Math.min(trigger.left, bounds.right - width)),
        gap,
    };
}

// Find the largest fitting extension with at most seven measurements instead
// of repeatedly rewriting and measuring the live dropdown's DOM.
export function fitGroupOptionText(source, width, candidateAt, measure) {
    if (measure(source) >= width) return source;
    let low = 0;
    let high = 48;
    while (low < high) {
        const middle = Math.ceil((low + high) / 2);
        if (measure(candidateAt(middle)) <= width) low = middle;
        else high = middle - 1;
    }
    return low ? candidateAt(low) : source;
}
