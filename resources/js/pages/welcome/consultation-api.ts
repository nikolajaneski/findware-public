export type BookingSettings = {
    configured: true;
    timezone: string;
    duration_minutes: 20;
    first_date: string;
    last_date: string;
};

export type BookingSlot = { start: string; end: string; label: string };
export type BookingResult = {
    reference: string;
    retry_reference?: string;
    status: 'pending' | 'uncertain' | 'confirmed';
    start: string;
    end: string;
    timezone: string;
    duration_minutes: 20;
    meeting_url: string | null;
};

export class BookingApiError extends Error {
    constructor(
        public status: number,
        public code: string,
        message: string,
        public fields: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export function createBookingKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    if (typeof globalThis.crypto?.getRandomValues !== 'function') {
        throw new Error(
            'This browser cannot create a secure booking reference. Please use a current browser.',
        );
    }

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}

function verifiedResult(value: BookingResult): BookingResult {
    const start = new Date(value.start).getTime();
    const end = new Date(value.end).getTime();

    if (
        !['pending', 'uncertain', 'confirmed'].includes(value.status) ||
        !/^[a-f\d-]{36}$/i.test(value.reference) ||
        value.duration_minutes !== 20 ||
        !Number.isFinite(start) ||
        end - start !== 20 * 60000 ||
        typeof value.timezone !== 'string' ||
        (value.retry_reference !== undefined &&
            (typeof value.retry_reference !== 'string' ||
                value.retry_reference.length === 0 ||
                value.retry_reference.length > 8192))
    ) {
        throw new Error('The booking outcome could not be verified.');
    }

    new Intl.DateTimeFormat('en-GB', { timeZone: value.timezone }).format();

    return value;
}

async function read<T>(response: Response): Promise<T> {
    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new BookingApiError(
            response.status,
            body?.code ?? 'request_failed',
            response.status === 419
                ? 'Your session expired. Reload this page before trying again.'
                : response.status === 429
                  ? 'Please wait a minute before trying again.'
                  : response.status >= 500 && !body?.code
                    ? 'Booking could not be checked. Please try again later.'
                    : (body?.message ??
                      'Booking could not be checked. Please try again later.'),
            body?.errors ?? {},
        );
    }

    if (!body || typeof body !== 'object') {
        throw new Error('The booking response could not be verified.');
    }

    return body as T;
}

export async function getBooking<T>(
    path: string,
    signal?: AbortSignal,
    reference?: string,
): Promise<T> {
    const value = await read<T>(
        await fetch(`/consultation/${path}`, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                ...(reference ? { 'X-Consultation-Reference': reference } : {}),
            },
            cache: 'no-store',
            signal,
        }),
    );

    return path.startsWith('bookings/')
        ? (verifiedResult(value as BookingResult) as T)
        : value;
}

export async function submitBooking(
    data: Record<string, unknown>,
    reference?: string,
): Promise<BookingResult> {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));
    const token = cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : '';

    return verifiedResult(
        await read<BookingResult>(
            await fetch('/consultation/bookings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': token,
                    ...(reference
                        ? { 'X-Consultation-Reference': reference }
                        : {}),
                },
                body: JSON.stringify(data),
            }),
        ),
    );
}
