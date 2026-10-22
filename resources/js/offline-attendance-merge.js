export function requiresMergedAttendanceRefresh(operation) {
    return operation?.queued_offline !== false;
}

export function shouldRefreshMergedAttendance(syncedOperations, remainingOperations) {
    return syncedOperations.some(requiresMergedAttendanceRefresh)
        && !remainingOperations.some((operation) => operation.state !== 'conflict');
}
