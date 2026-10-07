import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    BookingApiError,
    createBookingKey,
    getBooking,
    submitBooking,
} from './consultation-api';
import type {
    BookingResult,
    BookingSettings,
    BookingSlot,
} from './consultation-api';

const requestStorage = 'lgp:consultation:request';
const fallbackTimezone = 'Europe/Skopje';

function visitorTimezone(): string {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
        new Intl.DateTimeFormat('en-GB', { timeZone: zone }).format();

        return zone;
    } catch {
        return fallbackTimezone;
    }
}

function dateInZone(instant: number | string, zone: string): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date(instant));

    return ['year', 'month', 'day']
        .map((type) => parts.find((part) => part.type === type)?.value)
        .join('-');
}

function shiftDate(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

// Find the actual local-day boundary; a DST day need not be 24 hours or start at 00:00.
function dayBoundary(date: string, zone: string): number {
    const midnight = Date.parse(`${date}T00:00:00Z`);
    let low = midnight - 86400000;
    let high = midnight + 86400000;

    while (low < high) {
        const middle = Math.floor((low + high) / 120000) * 60000;

        if (dateInZone(middle, zone) < date) {
            low = middle + 60000;
        } else {
            high = middle;
        }
    }

    return low;
}

function visitorBounds(settings: BookingSettings, zone: string) {
    return {
        first_date: dateInZone(
            dayBoundary(settings.first_date, settings.timezone),
            zone,
        ),
        last_date: dateInZone(
            dayBoundary(shiftDate(settings.last_date, 1), settings.timezone) -
                1,
            zone,
        ),
    };
}

function ownerDates(
    date: string,
    zone: string,
    settings: BookingSettings,
): string[] {
    const first = dateInZone(dayBoundary(date, zone), settings.timezone);
    const last = dateInZone(
        dayBoundary(shiftDate(date, 1), zone) - 1,
        settings.timezone,
    );
    const dates = [];

    for (let day = first; day <= last; day = shiftDate(day, 1)) {
        if (day >= settings.first_date && day <= settings.last_date) {
            dates.push(day);
        }
    }

    return dates;
}

function slotTime(start: string, zone: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: zone,
        hour: '2-digit',
        minute: '2-digit',
        timeZoneName: 'shortOffset',
    }).format(new Date(start));
}

function savedRequest(): {
    key: string;
    start: string;
    reference?: string;
} | null {
    try {
        const saved = JSON.parse(
            sessionStorage.getItem(requestStorage) ?? 'null',
        );

        return typeof saved?.key === 'string' &&
            /^[a-f\d-]{36}$/i.test(saved.key) &&
            typeof saved.start === 'string' &&
            (saved.reference === undefined ||
                (typeof saved.reference === 'string' &&
                    saved.reference.length <= 8192))
            ? saved
            : null;
    } catch {
        return null;
    }
}

function keepRequest(key: string, start: string, reference?: string) {
    try {
        sessionStorage.setItem(
            requestStorage,
            JSON.stringify({ key, start, reference }),
        );
    } catch {
        // The mounted form still retains its idempotency key if browser storage is unavailable.
    }
}

function clearRequest() {
    try {
        sessionStorage.removeItem(requestStorage);
    } catch {
        // No attendee details are stored in browser storage.
    }
}

function MonthPicker({
    settings,
    date,
    onSelect,
}: {
    settings: Pick<BookingSettings, 'first_date' | 'last_date'>;
    date: string;
    onSelect: (date: string) => void;
}) {
    const [month, setMonth] = useState(date.slice(0, 7));
    const [year, number] = month.split('-').map(Number);
    const first = new Date(Date.UTC(year, number - 1, 1));
    const count = new Date(Date.UTC(year, number, 0)).getUTCDate();
    const offset = (first.getUTCDay() + 6) % 7;
    const move = (amount: number) => {
        const next = new Date(Date.UTC(year, number - 1 + amount, 1));
        setMonth(next.toISOString().slice(0, 7));
    };

    return (
        <div className="lgp-booking-month">
            <div className="lgp-booking-month-heading">
                <button
                    type="button"
                    aria-label="Previous month"
                    disabled={month <= settings.first_date.slice(0, 7)}
                    onClick={() => move(-1)}
                >
                    <ArrowLeft size={18} />
                </button>
                <h3 aria-live="polite">
                    {first.toLocaleDateString('en-GB', {
                        month: 'long',
                        year: 'numeric',
                        timeZone: 'UTC',
                    })}
                </h3>
                <button
                    type="button"
                    aria-label="Next month"
                    disabled={month >= settings.last_date.slice(0, 7)}
                    onClick={() => move(1)}
                >
                    <ArrowRight size={18} />
                </button>
            </div>
            <div
                className="lgp-booking-days"
                role="group"
                aria-label="Choose a consultation date"
            >
                {['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].map((day) => (
                    <span
                        className="lgp-booking-weekday"
                        key={day}
                        aria-hidden="true"
                    >
                        {day}
                    </span>
                ))}
                {Array.from({ length: offset }, (_, i) => (
                    <span key={`empty-${i}`} aria-hidden="true" />
                ))}
                {Array.from({ length: count }, (_, i) => {
                    const value = `${month}-${String(i + 1).padStart(2, '0')}`;
                    const label = new Date(
                        `${value}T12:00:00Z`,
                    ).toLocaleDateString('en-GB', {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                        timeZone: 'UTC',
                    });

                    return (
                        <button
                            key={value}
                            type="button"
                            disabled={
                                value < settings.first_date ||
                                value > settings.last_date
                            }
                            aria-label={label}
                            aria-pressed={date === value}
                            onClick={() => onSelect(value)}
                        >
                            {i + 1}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default function ConsultationBooking() {
    const [settings, setSettings] = useState<BookingSettings | null>(null);
    const [timezone, setTimezone] = useState(fallbackTimezone);
    const [initializing, setInitializing] = useState(true);
    const [date, setDate] = useState('');
    const [slots, setSlots] = useState<BookingSlot[]>([]);
    const [checking, setChecking] = useState(false);
    const [selected, setSelected] = useState('');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [brief, setBrief] = useState('');
    const [consent, setConsent] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [fields, setFields] = useState<Record<string, string[]>>({});
    const [result, setResult] = useState<BookingResult | null>(null);
    const key = useRef<string | null>(null);
    const retryReference = useRef<string | undefined>(undefined);
    const inFlight = useRef(false);
    const resultHeading = useRef<HTMLHeadingElement>(null);

    useEffect(() => {
        const abort = new AbortController();

        const initialize = async () => {
            try {
                const config = await getBooking<BookingSettings>(
                    'settings',
                    abort.signal,
                );

                if (
                    !config.configured ||
                    config.duration_minutes !== 20 ||
                    !config.timezone ||
                    !/^\d{4}-\d{2}-\d{2}$/.test(config.first_date) ||
                    !/^\d{4}-\d{2}-\d{2}$/.test(config.last_date)
                ) {
                    throw new Error('Booking setup is incomplete.');
                }

                new Intl.DateTimeFormat('en-GB', {
                    timeZone: config.timezone,
                }).format();
                const zone = visitorTimezone();
                const bounds = visitorBounds(config, zone);
                const today = dateInZone(Date.now(), zone);
                setTimezone(zone);
                setSettings(config);
                setChecking(true);
                setDate(
                    today < bounds.first_date
                        ? bounds.first_date
                        : today > bounds.last_date
                          ? bounds.last_date
                          : today,
                );
                const saved = savedRequest();

                if (saved) {
                    key.current = saved.key;
                    retryReference.current = saved.reference;

                    try {
                        setResult(
                            await getBooking<BookingResult>(
                                `bookings/${saved.key}`,
                                abort.signal,
                                saved.reference,
                            ),
                        );
                    } catch (reason) {
                        if (
                            reason instanceof BookingApiError &&
                            reason.status === 409 &&
                            [
                                'event_cancelled',
                                'booking_rejected',
                                'slot_unavailable',
                            ].includes(reason.code)
                        ) {
                            clearRequest();
                            key.current = null;
                            retryReference.current = undefined;

                            if (reason.status === 409) {
                                setError(reason.message);
                            }
                        } else if (!abort.signal.aborted) {
                            setResult({
                                reference: saved.key,
                                start: saved.start,
                                end: new Date(
                                    new Date(saved.start).getTime() +
                                        20 * 60000,
                                ).toISOString(),
                                status: 'uncertain',
                                timezone: config.timezone,
                                duration_minutes: 20,
                                meeting_url: null,
                            });
                        }
                    }
                }
            } catch (reason) {
                if (!abort.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Booking is not available yet.',
                    );
                }
            } finally {
                if (!abort.signal.aborted) {
                    setInitializing(false);
                }
            }
        };

        void initialize();

        return () => abort.abort();
    }, []);

    useEffect(() => {
        if (!settings || !date || result) {
            return;
        }

        const abort = new AbortController();

        Promise.all(
            ownerDates(date, timezone, settings).map((ownerDate) =>
                getBooking<{ slots: BookingSlot[]; duration_minutes: number }>(
                    `availability?date=${encodeURIComponent(ownerDate)}`,
                    abort.signal,
                ),
            ),
        )
            .then((responses) => {
                for (const data of responses) {
                    if (
                        !Array.isArray(data.slots) ||
                        data.duration_minutes !== 20 ||
                        !data.slots.every(
                            (slot) =>
                                Number.isFinite(
                                    new Date(slot.start).getTime(),
                                ) &&
                                new Date(slot.end).getTime() -
                                    new Date(slot.start).getTime() ===
                                    20 * 60000 &&
                                typeof slot.label === 'string',
                        )
                    ) {
                        throw new Error(
                            'Available times could not be verified.',
                        );
                    }
                }

                if (!abort.signal.aborted) {
                    const available = [
                        ...new Map(
                            responses
                                .flatMap((data) => data.slots)
                                .filter(
                                    (slot) =>
                                        dateInZone(slot.start, timezone) ===
                                        date,
                                )
                                .map((slot) => [
                                    slot.start,
                                    {
                                        ...slot,
                                        label: slotTime(slot.start, timezone),
                                    },
                                ]),
                        ).values(),
                    ].sort((a, b) => Date.parse(a.start) - Date.parse(b.start));
                    setSlots(available);
                    setSelected((current) =>
                        available.some((slot) => slot.start === current)
                            ? current
                            : '',
                    );
                }
            })
            .catch((reason: unknown) => {
                if (!abort.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Available times could not be checked.',
                    );
                }
            })
            .finally(() => {
                if (!abort.signal.aborted) {
                    setChecking(false);
                }
            });

        return () => abort.abort();
    }, [settings, date, result, timezone]);

    useEffect(() => {
        if (result) {
            if (result.retry_reference) {
                retryReference.current = result.retry_reference;
                keepRequest(
                    result.reference,
                    result.start,
                    result.retry_reference,
                );
            }

            resultHeading.current?.focus();
        }
    }, [result]);

    const confirm = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!selected || !settings || inFlight.current) {
            return;
        }

        inFlight.current = true;
        setSubmitting(true);
        setError('');
        setFields({});
        let requestStarted = false;
        const newRequest =
            key.current === null && retryReference.current === undefined;

        try {
            key.current ??= createBookingKey();
            keepRequest(key.current, selected, retryReference.current);
            requestStarted = true;
            setResult(
                await submitBooking(
                    {
                        name,
                        email,
                        brief,
                        consent,
                        start: selected,
                        idempotency_key: key.current,
                        website: '',
                    },
                    retryReference.current,
                ),
            );
        } catch (reason) {
            if (!requestStarted || key.current === null) {
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'A secure booking reference could not be created.',
                );
            } else if (
                reason instanceof BookingApiError &&
                ((reason.status < 500 &&
                    reason.code !== 'idempotency_mismatch') ||
                    [
                        'setup_required',
                        'calendar_unavailable',
                        'booking_rejected',
                    ].includes(reason.code))
            ) {
                setError(reason.message);
                setFields(reason.fields);

                if (
                    (reason.status === 419 && newRequest) ||
                    reason.status === 409 ||
                    reason.status === 422 ||
                    reason.status === 503
                ) {
                    clearRequest();
                    key.current = null;
                    retryReference.current = undefined;
                }

                if (reason.code === 'slot_unavailable') {
                    setSlots((current) =>
                        current.filter((slot) => slot.start !== selected),
                    );
                    setSelected('');
                }
            } else {
                setResult({
                    reference: key.current,
                    status: 'uncertain',
                    start: selected,
                    end: new Date(
                        new Date(selected).getTime() + 20 * 60000,
                    ).toISOString(),
                    timezone: settings.timezone,
                    duration_minutes: 20,
                    meeting_url: null,
                });
            }
        } finally {
            inFlight.current = false;
            setSubmitting(false);
        }
    };

    const checkStatus = async () => {
        if (!result || submitting) {
            return;
        }

        setSubmitting(true);
        setError('');

        try {
            setResult(
                await getBooking<BookingResult>(
                    `bookings/${result.reference}`,
                    undefined,
                    retryReference.current,
                ),
            );
        } catch (reason) {
            if (
                reason instanceof BookingApiError &&
                reason.status === 409 &&
                [
                    'event_cancelled',
                    'booking_rejected',
                    'slot_unavailable',
                ].includes(reason.code)
            ) {
                clearRequest();
                key.current = null;
                retryReference.current = undefined;
                setResult(null);
                setChecking(true);
                setSelected('');
                setSlots([]);
            }

            setError(
                reason instanceof Error
                    ? reason.message
                    : 'The booking status could not be checked.',
            );
        } finally {
            setSubmitting(false);
        }
    };

    const when = (start: string, timezone: string) =>
        new Date(start).toLocaleString('en-GB', {
            dateStyle: 'full',
            timeStyle: 'short',
            timeZone: timezone,
        });

    const chooseDate = (value: string) => {
        if (value === date || submitting) {
            return;
        }

        setChecking(true);
        setSlots([]);
        setSelected('');
        setError('');
        setDate(value);
    };

    const bounds = settings ? visitorBounds(settings, timezone) : null;

    return (
        <section
            className="lgp-booking-calendar"
            aria-labelledby="calendar-title"
            aria-busy={initializing || checking || submitting}
        >
            <h2 id="calendar-title">Book your free consultation</h2>
            {initializing ? (
                <p className="lgp-booking-status-message" role="status">
                    Checking consultation booking…
                </p>
            ) : !settings ? (
                <div className="lgp-booking-unavailable">
                    <p className="lgp-booking-status">
                        Booking is being prepared
                    </p>
                    <p>
                        {error || 'Consultation booking is not available yet.'}
                    </p>
                    <p>
                        Our calendar connection and availability are still being
                        configured.
                    </p>
                </div>
            ) : result ? (
                <div className="lgp-booking-result">
                    <h3 ref={resultHeading} tabIndex={-1}>
                        {result.status === 'confirmed'
                            ? 'Your consultation is confirmed'
                            : 'Checking your booking'}
                    </h3>
                    {result.status === 'confirmed' ? (
                        <Check size={28} aria-hidden="true" />
                    ) : (
                        <p role="status">
                            The calendar has not confirmed this booking yet.
                            Keep this reference while we check it; please avoid
                            submitting another request.
                        </p>
                    )}
                    <p>{when(result.start, timezone)} · 20 minutes</p>
                    <p className="lgp-booking-timezone">
                        Times shown in {timezone.replaceAll('_', ' ')}.
                    </p>
                    <p className="lgp-booking-reference">
                        Reference: {result.reference}
                    </p>
                    {result.status === 'confirmed' && (
                        <p>
                            Your booking is confirmed on our calendar. Keep this
                            reference and the Google Meet link below.
                        </p>
                    )}
                    {result.status === 'confirmed' &&
                        result.meeting_url?.startsWith('https://') && (
                            <a
                                className="lgp-button"
                                href={result.meeting_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open meeting link
                            </a>
                        )}
                    {result.status !== 'confirmed' && (
                        <button
                            type="button"
                            className="lgp-button"
                            disabled={submitting}
                            onClick={() => void checkStatus()}
                        >
                            Check booking status
                        </button>
                    )}
                </div>
            ) : (
                <>
                    <p className="lgp-booking-timezone">
                        Times shown in {timezone.replaceAll('_', ' ')}.
                    </p>
                    <MonthPicker
                        key={date.slice(0, 7)}
                        settings={bounds!}
                        date={date}
                        onSelect={chooseDate}
                    />
                    <div
                        className="lgp-booking-times"
                        role="group"
                        aria-label="Available 20-minute consultation times"
                    >
                        {checking ? (
                            <p role="status">Checking available times…</p>
                        ) : !slots.length && !error ? (
                            <p role="status">
                                No times are available on this date. Please
                                choose another date.
                            </p>
                        ) : (
                            slots.map((slot) => (
                                <button
                                    key={slot.start}
                                    type="button"
                                    aria-pressed={selected === slot.start}
                                    onClick={() => setSelected(slot.start)}
                                >
                                    {slot.label}
                                </button>
                            ))
                        )}
                    </div>
                    {selected && (
                        <form
                            onSubmit={(event) => void confirm(event)}
                            className="lgp-booking-form"
                        >
                            <p className="lgp-booking-selected">
                                {when(selected, timezone)} · 20 minutes
                            </p>
                            <fieldset disabled={submitting}>
                                <legend>Your details</legend>
                                <label htmlFor="consultation-name">Name</label>
                                <input
                                    id="consultation-name"
                                    autoComplete="name"
                                    required
                                    minLength={2}
                                    maxLength={120}
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    aria-invalid={!!fields.name}
                                    aria-describedby={
                                        fields.name
                                            ? 'consultation-name-error'
                                            : undefined
                                    }
                                />
                                {fields.name && (
                                    <p id="consultation-name-error">
                                        {fields.name[0]}
                                    </p>
                                )}
                                <label htmlFor="consultation-email">
                                    Email
                                </label>
                                <input
                                    id="consultation-email"
                                    type="email"
                                    autoComplete="email"
                                    required
                                    maxLength={254}
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    aria-invalid={!!fields.email}
                                    aria-describedby={
                                        fields.email
                                            ? 'consultation-email-error'
                                            : undefined
                                    }
                                />
                                {fields.email && (
                                    <p id="consultation-email-error">
                                        {fields.email[0]}
                                    </p>
                                )}
                                <label htmlFor="consultation-brief">
                                    What would you like to discuss?{' '}
                                    <span>(optional)</span>
                                </label>
                                <textarea
                                    id="consultation-brief"
                                    rows={3}
                                    maxLength={1000}
                                    value={brief}
                                    onChange={(e) => setBrief(e.target.value)}
                                />
                                <label className="lgp-booking-consent">
                                    <input
                                        type="checkbox"
                                        required
                                        checked={consent}
                                        onChange={(e) =>
                                            setConsent(e.target.checked)
                                        }
                                    />{' '}
                                    I agree to share these details with findward
                                    and Google Calendar to arrange this
                                    consultation.
                                </label>
                                <button className="lgp-button" type="submit">
                                    {submitting
                                        ? 'Confirming your consultation…'
                                        : 'Confirm free consultation'}
                                </button>
                            </fieldset>
                        </form>
                    )}
                </>
            )}
            {settings && error && (
                <p className="lgp-booking-error" role="alert">
                    {error}
                </p>
            )}
            <p className="lgp-booking-contact">Prefer to get in touch now?</p>
            <a className="lgp-booking-email" href="mailto:contact@findward.us">
                contact@findward.us
            </a>
        </section>
    );
}
