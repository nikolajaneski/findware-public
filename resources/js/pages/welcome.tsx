import { Head, Link, router, useRemember, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    Check,
    Compass,
    FolderSearch,
    Globe,
    MapPin,
    Plus,
    Search,
    Sparkles,
    Target,
    UserRound,
} from 'lucide-react';
import { useEffect, useRef } from 'react';
import ConsultationBooking from './welcome/consultation-booking';
import './welcome.css';

const contactEmail = 'contact@findward.us';
const sources = [
    {
        label: 'Business listings',
        detail: 'Maps & local context',
        icon: MapPin,
        color: 'peach',
        tilt: -12,
        drop: 20,
    },
    {
        label: 'Websites',
        detail: 'Offers & services',
        icon: Globe,
        color: 'blue',
        tilt: -6,
        drop: 78,
    },
    {
        label: 'Directories',
        detail: 'Industry context',
        icon: FolderSearch,
        color: 'lime',
        tilt: 2,
        drop: 104,
    },
    {
        label: 'Search results',
        detail: 'Relevant discoveries',
        icon: Search,
        color: 'purple',
        tilt: 8,
        drop: 78,
    },
    {
        label: 'Public profiles',
        detail: 'Supported public sources',
        icon: UserRound,
        color: 'pink',
        tilt: 14,
        drop: 20,
    },
];
const criteria = [
    {
        title: 'Location & market',
        icon: MapPin,
        tags: ['Geography', 'Language', 'Service area'],
        text: 'Choose where you want to find potential clients. We agree the locations, languages and service areas that make sense for your offer.',
    },
    {
        title: 'Industry & profile',
        icon: UserRound,
        tags: ['People', 'Companies', 'Sectors'],
        text: 'Define relevant businesses or individual professional profiles. The campaign follows your audience, with clear inclusion and exclusion criteria.',
    },
    {
        title: 'Services & needs',
        icon: Compass,
        tags: ['Your offer', 'Their services', 'Relevance'],
        text: 'Connect what you offer with what a prospect publicly describes. A potential need remains a research assessment until a conversation confirms it.',
    },
    {
        title: 'Evidence & fit',
        icon: Target,
        tags: ['Source links', 'Fit rationale', 'Unknowns'],
        text: 'Assess candidates against your criteria using recorded observations. Keep source evidence, missing information and the reasons behind each fit assessment visible.',
    },
];
const examples = [
    {
        initial: 'G',
        title: 'Gym',
        subtitle: 'Potential commercial cleaning customer',
        color: 'purple',
        criteria: 'Gym premises within the agreed service area',
        source: 'Public listing + gym website',
        rationale:
            'A local gym within your service area, matching the business types selected for your campaign.',
        next: 'Find the person who manages cleaning',
        fit: 'Potential customer',
    },
    {
        initial: 'R',
        title: 'Restaurant',
        subtitle: 'Potential commercial cleaning customer',
        color: 'peach',
        criteria: 'Restaurant location inside the service area',
        source: 'Public listing + restaurant website',
        rationale:
            'A restaurant in your service area with premises that match your commercial cleaning offer.',
        next: 'Identify the contact responsible for cleaning',
        fit: 'Potential customer',
    },
    {
        initial: 'S',
        title: 'Retail store',
        subtitle: 'Potential commercial cleaning customer',
        color: 'blue',
        criteria: 'Retail premises within the agreed service area',
        source: 'Public directory + store website',
        rationale:
            'A retail store in your service area, matching the location and business profile in your campaign brief.',
        next: 'Find the right contact for an introduction',
        fit: 'Potential customer',
    },
];
const journey = [
    [
        'Understand',
        'Your business, your offer and the people you want to reach.',
    ],
    ['Define', 'A tailored campaign with targeting criteria and exclusions.'],
    [
        'Discover',
        'Relevant people and companies through available online sources.',
    ],
    ['Assess', 'Analyse evidence, score fit and review the reasons and gaps.'],
    [
        'Prioritise',
        'A reviewed prospect list with source links and useful context.',
    ],
    [
        'Connect',
        'Outreach, funnels and conversion support where the engagement calls for it.',
    ],
];
const questions = [
    [
        'How does findward help me get more clients?',
        'We find and prioritise people and businesses that match your offer, then help you reach them with a relevant message. From research to outreach and follow-up, you get a focused path to new opportunities, so you can concentrate on winning the business.',
    ],
    [
        'How is the service tailored to my business?',
        'We start with your offer, your ideal customers and your goals. Then we build and personally manage a campaign focused on finding the people and businesses most relevant to you.',
    ],
    [
        'How do you find the right prospects?',
        'We research websites, business listings, directories, search results and social media profiles, then assess each prospect against your campaign criteria. You get a focused shortlist with clear reasons behind every recommendation.',
    ],
    [
        'What will I receive?',
        'A prioritised prospect list with source links, fit assessments and research notes. You’ll know who to approach, why they matter to your business and what could make your message relevant.',
    ],
    [
        'Can you also help with outreach?',
        'Yes. We can handle tailored outreach, follow-up and replies, helping you turn your prospect list into conversations. We can also support qualification and the steps from first contact to enquiry or sale.',
    ],
    [
        'How do I get started?',
        'Book a free 20-minute consultation. Tell us what you offer and who you want to reach, and we’ll discuss how findward can help you create new business opportunities.',
    ],
];

function ResearchSources() {
    const track = useRef<HTMLDivElement>(null);
    const phase = useRef(0);
    const swingTime = useRef(0);

    useEffect(() => {
        const element = track.current;

        if (!element) {
            return;
        }

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        const compact = window.matchMedia('(max-width: 760px)');
        const cards = Array.from(
            element.querySelectorAll<HTMLElement>('.lgp-source-card'),
        );
        const hero = element.closest<HTMLElement>('.lgp-hero');
        const actions = hero?.querySelector<HTMLElement>('.lgp-actions');
        let frame = 0;
        let previousTime = 0;
        let width = element.clientWidth;
        const spacing = 270;
        const period = cards.length * spacing;
        // Keep the rotated card and pin fully offscreen before wrapping.
        const wrapOffset = spacing + 64;
        const paint = () => {
            cards.forEach((card, index) => {
                const x =
                    ((index * spacing - phase.current + period) % period) -
                    wrapOffset;
                const position = Math.max(0, Math.min(1, (x + 124.5) / width));
                const y = 33 + 82 * (1 - (position * 2 - 1) ** 2);
                const angle = (position - 0.5) * 28;
                const sourceIndex = index % sources.length;
                const swing =
                    Math.sin(
                        (swingTime.current / (3800 + sourceIndex * 170)) *
                            Math.PI *
                            2 +
                            sourceIndex * 1.7,
                    ) * 2.5;
                // Swing around the pin hole without moving its attachment point.
                card.style.transform = `translate(${x}px, ${y}px) rotate(${angle}deg) translateY(-18.5px) rotate(${swing}deg) translateY(18.5px)`;
            });
        };
        const tick = (time: number) => {
            if (previousTime) {
                const elapsed = Math.min(64, time - previousTime);
                swingTime.current += elapsed;
                phase.current = (phase.current + elapsed * 0.041) % period;
            }

            previousTime = time;
            paint();
            frame = window.requestAnimationFrame(tick);
        };
        const update = () => {
            window.cancelAnimationFrame(frame);
            previousTime = 0;
            width = Math.max(1, element.clientWidth);

            if (hero && actions) {
                const bounds = hero.getBoundingClientRect();
                const scale = bounds.width / Math.max(1, hero.offsetWidth);
                const actionBottom =
                    (actions.getBoundingClientRect().bottom - bounds.top) /
                    scale;
                hero.style.setProperty(
                    '--lgp-source-top',
                    `${Math.max(484, actionBottom + 28)}px`,
                );
            }

            const moving = !reduced.matches && !compact.matches;
            element.dataset.moving = String(moving);

            if (!moving) {
                cards.forEach((card, index) => {
                    const source = sources[index % sources.length];
                    card.style.transform = `translateY(${source.drop}px) rotate(${source.tilt}deg)`;
                });

                return;
            }

            paint();

            if (!document.hidden) {
                frame = window.requestAnimationFrame(tick);
            }
        };
        const resize = new ResizeObserver(update);
        resize.observe(element);

        if (actions) {
            resize.observe(actions);
        }

        const copy = hero?.querySelector('.lgp-hero-copy');

        if (copy) {
            resize.observe(copy);
        }

        reduced.addEventListener('change', update);
        compact.addEventListener('change', update);
        document.addEventListener('visibilitychange', update);
        update();

        return () => {
            window.cancelAnimationFrame(frame);
            resize.disconnect();
            reduced.removeEventListener('change', update);
            compact.removeEventListener('change', update);
            document.removeEventListener('visibilitychange', update);
        };
    }, []);

    return (
        <div className="lgp-research-sources">
            <div ref={track} className="lgp-source-cards">
                {[...sources, ...sources].map(
                    (
                        { label, detail, icon: Icon, color, tilt, drop },
                        index,
                    ) => (
                        <article
                            key={`${label}-${index}`}
                            aria-hidden={
                                index >= sources.length ? true : undefined
                            }
                            className={`lgp-source-card lgp-${color} ${index >= sources.length ? 'lgp-source-copy' : ''}`}
                            style={{
                                transform: `translateY(${drop}px) rotate(${tilt}deg)`,
                            }}
                        >
                            <span
                                className="lgp-card-hole"
                                aria-hidden="true"
                            />
                            <div className="lgp-source-icon">
                                <Icon
                                    size={37}
                                    strokeWidth={1.7}
                                    aria-hidden="true"
                                />
                            </div>
                            <h2>{label}</h2>
                            <p>{detail}</p>
                        </article>
                    ),
                )}
            </div>
        </div>
    );
}

function ConsultationView({ onBack }: { onBack: () => void }) {
    const title = useRef<HTMLHeadingElement>(null);

    useEffect(() => {
        window.scrollTo(0, 0);
        title.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div className="lgp-public lgp-booking">
            <Head title="Free 20-minute consultation">
                <meta
                    name="description"
                    content="Book a free 20-minute consultation with findward to discuss your business, target audience and tailored campaign."
                />
            </Head>
            <header className="lgp-booking-header">
                <span className="lgp-brand">
                    <span aria-hidden="true" />
                    findward
                </span>
                <button
                    className="lgp-booking-back"
                    onClick={onBack}
                    type="button"
                >
                    <ArrowLeft size={18} aria-hidden="true" />
                    Back to homepage
                </button>
            </header>
            <main
                className="lgp-booking-main"
                aria-labelledby="consultation-title"
            >
                <section className="lgp-booking-intro">
                    <p className="lgp-kicker">
                        Your campaign starts with a conversation
                    </p>
                    <h1 id="consultation-title" ref={title} tabIndex={-1}>
                        Free 20-minute consultation.
                    </h1>
                    <p>
                        Tell us about your business, the clients you want to
                        reach and what you need from a campaign.
                    </p>
                    <ul className="lgp-booking-expectations">
                        <li>
                            <Check size={20} aria-hidden="true" /> Understand
                            your offer and goals
                        </li>
                        <li>
                            <Check size={20} aria-hidden="true" /> Explore your
                            target audience
                        </li>
                        <li>
                            <Check size={20} aria-hidden="true" /> Discuss a
                            practical next step
                        </li>
                    </ul>
                    <span className="lgp-booking-duration">
                        20 minutes · Free consultation
                    </span>
                </section>
                <ConsultationBooking />
            </main>
        </div>
    );
}

export default function Welcome({
    consultation = false,
}: {
    consultation?: boolean;
}) {
    const booking = consultation;
    const { url } = usePage();
    const [returnPosition, setReturnPosition] = useRemember(
        { target: '', scroll: 0 },
        'findward:consultation:return',
    );

    useEffect(() => {
        const replaceLegacyUrl = () => {
            if (
                window.location.pathname === '/' &&
                window.location.hash === '#consultation'
            ) {
                router.visit('/consultation', {
                    replace: true,
                    preserveState: true,
                });
            }
        };
        replaceLegacyUrl();
        window.addEventListener('hashchange', replaceLegacyUrl);

        return () => window.removeEventListener('hashchange', replaceLegacyUrl);
    }, []);

    useEffect(() => {
        const { target, scroll } = returnPosition;

        if (!booking && target) {
            const frame = window.requestAnimationFrame(() => {
                document.getElementById(target)?.focus({ preventScroll: true });
                window.scrollTo(0, scroll ?? 0);
            });

            return () => window.cancelAnimationFrame(frame);
        }
    }, [booking, returnPosition]);

    useEffect(() => {
        if (booking || returnPosition.target) return;
        const section = url.split(/[?#]/)[0].slice(1);
        if (!['approach', 'criteria', 'examples'].includes(section)) return;
        const frame = window.requestAnimationFrame(() => {
            document.getElementById(section)?.scrollIntoView();
        });
        return () => window.cancelAnimationFrame(frame);
    }, [booking, url, returnPosition.target]);

    const rememberReturn = (id: string) => {
        setReturnPosition({ target: id, scroll: window.scrollY });
    };
    const closeBooking = () => {
        if (returnPosition.target) {
            window.history.back();
        } else {
            router.visit('/', { preserveState: true });
        }
    };

    if (booking) {
        return <ConsultationView onBack={closeBooking} />;
    }

    return (
        <>
            <Head title="Tailored campaigns to find your next clients">
                <meta
                    name="description"
                    content="findward: personally managed client acquisition through tailored campaigns, online research, evidence-backed prospect lists and meaningful conversations."
                />
            </Head>
            <div className="lgp-public">
                <a className="lgp-skip" href="#main">
                    Skip to content
                </a>
                <header className="lgp-nav">
                    <Link
                        href="/"
                        className="lgp-brand"
                        aria-label="findward home"
                    >
                        findward
                    </Link>
                    <nav aria-label="Main navigation">
                        <a href="/approach">How it works</a>
                        <a href="/criteria">Your campaign</a>
                        <a href="/examples">The output</a>
                    </nav>
                    <Link
                        className="lgp-button lgp-nav-cta"
                        id="nav-discuss-campaign"
                        href="/consultation"
                        preserveState
                        onClick={() => rememberReturn('nav-discuss-campaign')}
                    >
                        Let's talk <ArrowUpRight size={17} aria-hidden="true" />
                    </Link>
                </header>
                <main id="main" tabIndex={-1}>
                    <section className="lgp-hero" aria-labelledby="hero-title">
                        <div className="lgp-hero-copy">
                            <h1 id="hero-title">
                                Your next clients.
                                <br />
                                Found with purpose.
                            </h1>
                            <p className="lgp-hero-description">
                                We understand your business, build a tailored
                                campaign and turn online research into
                                prioritised prospects and meaningful
                                conversations.
                            </p>
                            <div className="lgp-actions">
                                <Link
                                    className="lgp-button"
                                    id="hero-discuss-campaign"
                                    href="/consultation"
                                    preserveState
                                    onClick={() =>
                                        rememberReturn('hero-discuss-campaign')
                                    }
                                >
                                    Discuss your campaign{' '}
                                    <ArrowUpRight
                                        size={18}
                                        aria-hidden="true"
                                    />
                                </Link>
                                <a
                                    className="lgp-button lgp-button-white"
                                    href="/approach"
                                >
                                    See how it works{' '}
                                    <ArrowDown size={18} aria-hidden="true" />
                                </a>
                            </div>
                        </div>
                        <div className="lgp-source-display">
                            <div className="lgp-source-arc" aria-hidden="true">
                                <svg
                                    viewBox="0 0 1200 220"
                                    preserveAspectRatio="none"
                                >
                                    <path d="M-40 0 Q600 220 1240 0" />
                                </svg>
                            </div>
                            <ResearchSources />
                            <p className="lgp-source-note">
                                Available public sources, selected for your
                                campaign. No private-profile access implied.
                            </p>
                        </div>
                    </section>
                    <section
                        className="lgp-benefits"
                        aria-labelledby="benefits-title"
                    >
                        <div className="lgp-section-intro">
                            <p className="lgp-kicker">
                                A better starting point
                            </p>
                            <h2 id="benefits-title">
                                Less guesswork.
                                <br />
                                More direction.
                            </h2>
                            <p>
                                Finding potential clients starts with
                                understanding what makes someone relevant to
                                you.
                            </p>
                        </div>
                        <div className="lgp-benefit-strip lgp-lime">
                            <span className="lgp-strip-icon">
                                <Target aria-hidden="true" />
                            </span>
                            <h3>Built around your business.</h3>
                            <p>
                                Your offer. Your audience.
                                <br />A campaign that connects the two.
                            </p>
                            <span className="lgp-strip-number">01</span>
                        </div>
                        <div className="lgp-benefit-strip lgp-pink">
                            <span className="lgp-strip-icon">
                                <FolderSearch aria-hidden="true" />
                            </span>
                            <h3>Research with a reason.</h3>
                            <p>
                                Source evidence, fit assessments
                                <br />
                                and the context behind each prospect.
                            </p>
                            <span className="lgp-strip-number">02</span>
                        </div>
                        <div className="lgp-benefit-strip lgp-blue">
                            <span className="lgp-strip-icon">
                                <Sparkles aria-hidden="true" />
                            </span>
                            <h3>A considered next move.</h3>
                            <p>
                                From prioritised prospects to
                                <br />
                                introductions and conversations.
                            </p>
                            <span className="lgp-strip-number">03</span>
                        </div>
                    </section>
                    <ul className="lgp-word-rail" aria-label="Campaign focus">
                        <li>
                            Understand your offer
                            <Plus aria-hidden="true" />
                        </li>
                        <li>
                            Find the right fit
                            <Plus aria-hidden="true" />
                        </li>
                        <li>Start a conversation</li>
                    </ul>
                    <section
                        id="criteria"
                        className="lgp-criteria"
                        aria-labelledby="criteria-title"
                    >
                        <div className="lgp-section-intro">
                            <p className="lgp-kicker">
                                Your campaign, your criteria
                            </p>
                            <h2 id="criteria-title">
                                Who makes sense
                                <br />
                                for your business?
                            </h2>
                            <p>
                                We define the brief together. Explore the
                                criteria that give discovery a clear direction.
                            </p>
                        </div>
                        <div className="lgp-criteria-list">
                            {criteria.map(
                                ({ title, icon: Icon, tags, text }, index) => (
                                    <details key={title}>
                                        <summary>
                                            <span
                                                className={`lgp-criterion-icon lgp-${['peach', 'purple', 'blue', 'pink'][index]}`}
                                            >
                                                <Icon
                                                    size={25}
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <h3>{title}</h3>
                                            <span className="lgp-tags">
                                                {tags.map((tag) => (
                                                    <span key={tag}>{tag}</span>
                                                ))}
                                            </span>
                                            <Plus
                                                size={22}
                                                className="lgp-details-plus"
                                                aria-hidden="true"
                                            />
                                        </summary>
                                        <p>{text}</p>
                                    </details>
                                ),
                            )}
                        </div>
                        <Link
                            className="lgp-button lgp-button-outline"
                            id="criteria-discuss-campaign"
                            href="/consultation"
                            preserveState
                            onClick={() =>
                                rememberReturn('criteria-discuss-campaign')
                            }
                        >
                            Shape your campaign{' '}
                            <ArrowUpRight size={18} aria-hidden="true" />
                        </Link>
                    </section>
                    <section
                        id="examples"
                        className="lgp-examples"
                        aria-labelledby="examples-title"
                    >
                        <div className="lgp-section-intro">
                            <p className="lgp-kicker">
                                The output, made useful
                            </p>
                            <h2 id="examples-title">What you receive</h2>
                            <p>
                                Example campaign: a commercial cleaning company
                                looking for new clients in its service area. We
                                agree the area, business locations and types,
                                then research potential customers.
                            </p>
                        </div>
                        <p className="lgp-example-note">
                            Illustrative examples. Not client results or
                            confirmed buying intent.
                        </p>
                        <div className="lgp-example-grid">
                            {examples.map((example) => (
                                <article
                                    key={example.title}
                                    className="lgp-prospect-card"
                                >
                                    <div
                                        className={`lgp-prospect-art lgp-${example.color}`}
                                        aria-hidden="true"
                                    >
                                        <div className="lgp-orbit lgp-orbit-one" />
                                        <div className="lgp-orbit lgp-orbit-two" />
                                        <span className="lgp-prospect-monogram">
                                            {example.initial}
                                        </span>
                                        <span className="lgp-art-label">
                                            <Check size={13} />
                                            Evidence + context
                                        </span>
                                    </div>
                                    <div className="lgp-prospect-body">
                                        <p className="lgp-prospect-fit">
                                            <span />
                                            {example.fit}
                                        </p>
                                        <h3>{example.title}</h3>
                                        <p className="lgp-prospect-subtitle">
                                            {example.subtitle}
                                        </p>
                                        <dl>
                                            <div>
                                                <dt>Matched criteria</dt>
                                                <dd>{example.criteria}</dd>
                                            </div>
                                            <div>
                                                <dt>Source evidence</dt>
                                                <dd>{example.source}</dd>
                                            </div>
                                            <div>
                                                <dt>Fit rationale</dt>
                                                <dd>{example.rationale}</dd>
                                            </div>
                                        </dl>
                                        <p className="lgp-prospect-next">
                                            <ArrowRight
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            <span>{example.next}</span>
                                        </p>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                    <section
                        id="approach"
                        className="lgp-journey"
                        aria-labelledby="journey-title"
                    >
                        <div className="lgp-journey-heading">
                            <p className="lgp-kicker">
                                From brief to next opportunity
                            </p>
                            <h2 id="journey-title">
                                A clear path.
                                <br />
                                Made for you.
                            </h2>
                            <p>
                                Six connected steps, shaped around your business
                                and the scope of your engagement.
                            </p>
                            <Link
                                className="lgp-button"
                                id="approach-discuss-campaign"
                                href="/consultation"
                                preserveState
                                onClick={() =>
                                    rememberReturn('approach-discuss-campaign')
                                }
                            >
                                Discuss your campaign{' '}
                                <ArrowUpRight size={18} aria-hidden="true" />
                            </Link>
                        </div>
                        <ol className="lgp-journey-steps">
                            {journey.map(([title, text], index) => (
                                <li key={title}>
                                    <span className="lgp-step-number">
                                        0{index + 1}
                                    </span>
                                    <div>
                                        <h3>{title}</h3>
                                        <p>{text}</p>
                                    </div>
                                    <ArrowDown size={18} aria-hidden="true" />
                                </li>
                            ))}
                        </ol>
                    </section>
                    <section
                        className="lgp-delivery"
                        aria-labelledby="delivery-title"
                    >
                        <p className="lgp-kicker">
                            A deliverable you can work with
                        </p>
                        <h2 id="delivery-title">
                            A shortlist.
                            <br />
                            And a way forward.
                        </h2>
                        <div className="lgp-delivery-pills">
                            <span>
                                <Check size={18} />
                                Prioritised prospects
                            </span>
                            <span>
                                <Check size={18} />
                                Source links
                            </span>
                            <span>
                                <Check size={18} />
                                Fit rationale
                            </span>
                            <span>
                                <Check size={18} />
                                Research notes
                            </span>
                            <span>
                                <Check size={18} />
                                Agreed next steps
                            </span>
                        </div>
                        <p>
                            Continue into outreach, funnels and conversion
                            support where the engagement calls for it. Relevant
                            conversations guide the next step; research alone
                            never guarantees a sale.
                        </p>
                    </section>
                    <section
                        id="questions"
                        className="lgp-faq"
                        aria-labelledby="faq-title"
                    >
                        <div>
                            <p className="lgp-kicker">Good questions</p>
                            <h2 id="faq-title">
                                Before we
                                <br />
                                get started.
                            </h2>
                            <p>
                                Have something else in mind?
                                <br />
                                <Link
                                    id="faq-talk"
                                    href="/consultation"
                                    onClick={() => rememberReturn('faq-talk')}
                                >
                                    Let's talk{' '}
                                    <ArrowUpRight
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </Link>
                            </p>
                        </div>
                        <div className="lgp-faq-list">
                            {questions.map(([question, answer]) => (
                                <details key={question}>
                                    <summary>
                                        <h3>{question}</h3>
                                        <Plus
                                            size={21}
                                            className="lgp-details-plus"
                                            aria-hidden="true"
                                        />
                                    </summary>
                                    <p>{answer}</p>
                                </details>
                            ))}
                        </div>
                    </section>
                    <section
                        id="contact"
                        className="lgp-contact"
                        aria-labelledby="contact-title"
                    >
                        <p className="lgp-kicker">
                            Your next chapter starts with a brief
                        </p>
                        <h2 id="contact-title">
                            Let's find
                            <br />
                            your next.
                        </h2>
                        <p>
                            Tell us about your business and the clients you want
                            to reach.
                        </p>
                        <Link
                            className="lgp-button"
                            id="contact-discuss-campaign"
                            href="/consultation"
                            preserveState
                            onClick={() =>
                                rememberReturn('contact-discuss-campaign')
                            }
                        >
                            Discuss your campaign{' '}
                            <ArrowUpRight size={20} aria-hidden="true" />
                        </Link>
                        <a
                            className="lgp-email"
                            href={`mailto:${contactEmail}`}
                        >
                            {contactEmail}
                        </a>
                    </section>
                </main>
                <footer className="lgp-footer">
                    <Link href="/" className="lgp-brand">
                        findward
                    </Link>
                    <p>
                        Tailored campaigns. Relevant prospects. Meaningful
                        conversations.
                    </p>
                    <a href="#main">
                        Back to top{' '}
                        <ArrowUpRight size={14} aria-hidden="true" />
                    </a>
                </footer>
            </div>
        </>
    );
}
