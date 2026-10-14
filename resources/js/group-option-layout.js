export function groupOptionColumns(serializedColumns, phone) {
    const columns = JSON.parse(serializedColumns);
    return phone ? columns.slice(0, 1) : columns;
}

export function fitGroupOptionColumnWidths(naturalWidths, rowWidth, minimumGap = 16) {
    const widths = naturalWidths.map(width => Math.max(0, Math.ceil(width)));
    const availableWidth = Math.max(0, Math.floor(rowWidth - ((widths.length + 1) * minimumGap)));
    const naturalTotal = widths.reduce((total, width) => total + width, 0);

    if (naturalTotal <= availableWidth || naturalTotal === 0) {
        return widths;
    }

    let remainingWidth = availableWidth;

    return widths.map((width, index) => {
        if (index === widths.length - 1) {
            return remainingWidth;
        }

        const fittedWidth = Math.floor((width / naturalTotal) * availableWidth);
        remainingWidth -= fittedWidth;

        return fittedWidth;
    });
}

export function widestGroupOptionColumnWidths(columnSets, measure) {
    const columnCount = Math.max(0, ...columnSets.map(columns => columns.length));

    return Array.from({ length: columnCount }, (_, column) => Math.max(
        0,
        ...columnSets.map(columns => measure(columns[column] || '', column)),
    ));
}

export function stableGroupOptionRowWidth(wrapperWidth, listInlinePadding = 0, panelInlineBorder = 0) {
    return Math.max(0, Math.floor(wrapperWidth - listInlinePadding - panelInlineBorder));
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

export function visibleMenuTrigger(trigger, bounds) {
    return {
        top: Math.max(bounds.top, Math.min(trigger.top, bounds.bottom)),
        bottom: Math.max(bounds.top, Math.min(trigger.bottom, bounds.bottom)),
        left: trigger.left,
        width: trigger.width,
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
