<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Scheduling\ScheduleService;
use App\Http\Controllers\Controller;
use App\Models\ScheduledStream;
use App\Models\StreamDestination;
use App\Models\StreamEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(private readonly ScheduleService $schedules) {}

    public function index(): View
    {
        $this->authorize('viewAny', ScheduledStream::class);

        return view('admin.schedules.index', [
            'upcoming' => ScheduledStream::with(['endpoint', 'destinations'])->whereIn('status', ['scheduled', 'waiting_for_source', 'live'])->orderBy('scheduled_at')->get(),
            'past' => ScheduledStream::with('endpoint')->whereIn('status', ['completed', 'cancelled', 'missed'])->latest('scheduled_at')->paginate(15),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ScheduledStream::class);

        return $this->form(new ScheduledStream(['timezone' => auth()->user()->timezone ?? config('app.timezone'), 'auto_start' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ScheduledStream::class);
        $this->schedules->create($this->validated($request), $request->user());

        return redirect()->route('admin.schedules.index')->with('status', 'Stream scheduled.');
    }

    public function edit(ScheduledStream $schedule): View
    {
        $this->authorize('update', $schedule);

        return $this->form($schedule);
    }

    public function update(Request $request, ScheduledStream $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);
        $this->schedules->update($schedule, $this->validated($request));

        return redirect()->route('admin.schedules.index')->with('status', 'Schedule updated.');
    }

    public function cancel(ScheduledStream $schedule): RedirectResponse
    {
        $this->authorize('update', $schedule);
        $this->schedules->cancel($schedule);

        return back()->with('status', 'Schedule cancelled.');
    }

    public function destroy(ScheduledStream $schedule): RedirectResponse
    {
        $this->authorize('delete', $schedule);
        $schedule->delete();

        return redirect()->route('admin.schedules.index')->with('status', 'Schedule deleted.');
    }

    private function form(ScheduledStream $schedule): View
    {
        return view('admin.schedules.form', [
            'schedule' => $schedule,
            'endpoints' => StreamEndpoint::where('is_enabled', true)->orderBy('name')->get(),
            'destinations' => StreamDestination::where('is_enabled', true)->orderBy('name')->get(),
            'selected' => $schedule->exists ? $schedule->destinations()->pluck('stream_destinations.id')->all() : [],
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    private function validated(Request $request): array
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'stream_endpoint_id' => ['required', Rule::exists('stream_endpoints', 'id')->where('tenant_id', $tenantId)],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone:all'],
            'destination_ids' => ['nullable', 'array'],
            'destination_ids.*' => [Rule::exists('stream_destinations', 'id')->where('tenant_id', $tenantId)],
            'recording_enabled' => ['nullable', 'boolean'],
            'auto_start' => ['nullable', 'boolean'],
            'auto_stop' => ['nullable', 'boolean'],
            'auto_stop_time' => ['nullable', 'date_format:H:i', 'required_if:auto_stop,1'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'thumbnail' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('akstream.security.max_upload_kb')],
        ]);

        $scheduledAt = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time'], $data['timezone'])->utc();
        $autoStopAt = null;
        if (! empty($data['auto_stop']) && ! empty($data['auto_stop_time'])) {
            $autoStopAt = Carbon::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['auto_stop_time'], $data['timezone'])->utc();
            if ($autoStopAt->lte($scheduledAt)) {
                $autoStopAt->addDay();
            }
        }

        $out = [
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'stream_endpoint_id' => $data['stream_endpoint_id'],
            'scheduled_at' => $scheduledAt,
            'auto_stop_at' => $autoStopAt,
            'timezone' => $data['timezone'],
            'auto_start' => (bool) ($data['auto_start'] ?? false),
            'auto_stop' => (bool) ($data['auto_stop'] ?? false),
            'recording_enabled' => (bool) ($data['recording_enabled'] ?? false),
            'notes' => $data['notes'] ?? null,
            'destination_ids' => $data['destination_ids'] ?? [],
        ];

        if ($request->hasFile('thumbnail')) {
            $file = $request->file('thumbnail');
            if (! in_array($file->getMimeType(), config('akstream.security.allowed_upload_mimes'), true)) {
                abort(422, 'Invalid image type');
            }
            $out['thumbnail_path'] = $file->storeAs('thumbnails', Str::ulid().'.'.$file->guessExtension(), 'local');
        }

        return $out;
    }
}
