export function fuelDate(value, fallback = 'Not yet') {
    if (!value) return fallback;
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return fallback;
    const pad = (number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function fuelNumber(value) {
    return Number(value ?? 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
}

export function fuelMoney(amount, currency) {
    return `${currency || 'SAR'} ${fuelNumber(Number(amount ?? 0) / 100)}`;
}

export function syncRunView(run) {
    const received = run.summary?.received ?? Number(run.imported ?? 0) + Number(run.summary?.updated ?? 0);
    const pending = ['queued', 'running'].includes(run.status);
    const waiting = run.status === 'queued' && Date.now() - new Date(run.created_at).getTime() > 120000;
    const labels = { queued: 'Queued', running: 'Importing', completed: 'Completed', error: 'Failed' };
    return {
        ...run,
        pending,
        label: labels[run.status] ?? run.status,
        tone: run.status === 'error' ? 'danger' : pending ? 'warning' : 'success',
        date: fuelDate(run.finished_at || run.started_at || run.created_at),
        window: run.from && run.to ? `${String(run.from).slice(0, 10)} – ${String(run.to).slice(0, 10)}` : 'Date range unavailable for this earlier run',
        result:
            run.status === 'error'
                ? run.error || 'The import failed. Retry this date range.'
                : waiting
                  ? 'Still queued. Check that the background worker is running.'
                  : run.status === 'queued'
                    ? 'Waiting for the background worker.'
                    : run.status === 'running'
                      ? 'Reading transactions from the provider.'
                      : received === 0
                        ? 'No transactions returned for this date range.'
                        : `${fuelNumber(run.imported)} new · ${fuelNumber(run.summary?.updated)} updated · ${fuelNumber(run.unmatched)} need matching`,
        received,
    };
}
