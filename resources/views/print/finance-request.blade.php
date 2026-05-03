<x-print.layout :title="'Finance request '.$request->request_no">
    <div class="header">
        <div>
            <h1 class="title">Finance Request</h1>
            <div class="subtitle">{{ $organization['name'] ?: 'Alkhair' }}</div>
            @if ($organization['address'] || $organization['phone'] || $organization['email'])
                <div class="subtitle">
                    {{ $organization['address'] ?: '' }}
                    @if ($organization['phone']) <span> | {{ $organization['phone'] }}</span> @endif
                    @if ($organization['email']) <span> | {{ $organization['email'] }}</span> @endif
                </div>
            @endif
        </div>
        <div>
            <div class="meta-label">Request No</div>
            <div class="meta-value">{{ $request->request_no }}</div>
            <div class="subtitle">{{ ucfirst($request->type) }} | {{ ucfirst($request->status) }}</div>
        </div>
    </div>

    <div class="section meta-grid">
        <div class="meta-card">
            <span class="meta-label">Requested by</span>
            <div class="meta-value">{{ $request->requestedBy?->name ?: '-' }}</div>
            <div class="subtitle">{{ $request->created_at?->format('Y-m-d H:i') }}</div>
        </div>
        <div class="meta-card">
            <span class="meta-label">Reviewed by</span>
            <div class="meta-value">{{ $request->reviewedBy?->name ?: '-' }}</div>
            <div class="subtitle">{{ $request->accepted_at?->format('Y-m-d H:i') }}</div>
        </div>
    </div>

    <div class="section">
        <h2>Amounts</h2>
        <table>
            <tbody>
                <tr><th>Requested amount</th><td>{{ number_format((float) $request->requested_amount, 2) }} {{ $request->requestedCurrency?->code }}</td></tr>
                <tr><th>Accepted amount</th><td>{{ number_format((float) $request->accepted_amount, 2) }} {{ $request->acceptedCurrency?->code }}</td></tr>
                <tr><th>Cash box</th><td>{{ $request->cashBox?->name ?: '-' }}</td></tr>
                <tr><th>Activity</th><td>{{ $request->activity?->title ?: '-' }}</td></tr>
                <tr><th>Teacher</th><td>{{ $request->teacher ? trim($request->teacher->first_name.' '.$request->teacher->last_name) : '-' }}</td></tr>
                <tr><th>Category</th><td>{{ $request->category?->name ?: '-' }}</td></tr>
            </tbody>
        </table>
    </div>

    @if ($request->requested_reason || $request->review_notes)
        <div class="section">
            <h2>Notes</h2>
            @if ($request->requested_reason)<div class="note-box">{{ $request->requested_reason }}</div>@endif
            @if ($request->review_notes)<div class="note-box" style="margin-top: 8px;">{{ $request->review_notes }}</div>@endif
        </div>
    @endif

    <div class="section meta-grid">
        <div class="meta-card">
            <span class="meta-label">Receiver signature</span>
            <div style="height: 48px;"></div>
        </div>
        <div class="meta-card">
            <span class="meta-label">Finance signature</span>
            <div style="height: 48px;"></div>
        </div>
    </div>
</x-print.layout>
