import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { ApiError } from '../api/client';
import { keys, useCreateBooking, useProvider } from '../api/hooks';
import type { PaymentMethod } from '../api/types';
import { Button } from '../design/components/Button';
import { Loading, Notice } from '../design/components/Notice';
import { Stepper } from '../design/components/Stepper';
import { formFieldForApiField, type DetailsValues } from '../lib/details';
import type { Slot } from '../lib/time';
import { formatLongDateTime, visitorTimezone } from '../lib/time';
import { BookingSummary } from './BookingSummary';
import { DateSlotPicker } from './DateSlotPicker';
import { DetailsForm } from './DetailsForm';
import './booking.css';

const STEPS = ['Time', 'Your details', 'Review & pay', 'Done'];
const SLOT_GONE = ['slot_unavailable', 'daily_limit_reached'];
const METHOD_LABEL: Record<PaymentMethod, string> = {
  upi: 'UPI (GPay, PhonePe, Paytm, BHIM…)',
  free: 'No payment needed',
  razorpay_link: 'Pay online: card, UPI app, netbanking or wallet (Razorpay)',
};

/** Each step's heading takes focus when the step appears, so keyboard and screen-reader users land on it. */
function StepTitle({ children }: { children: string }) {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => ref.current?.focus({ preventScroll: true }), []);
  return (
    <h2 className="step-title" tabIndex={-1} ref={ref}>
      {children}
    </h2>
  );
}

export default function BookingPage() {
  const { provider: providerSlug = '', service: serviceSlug = '' } = useParams();
  const query = useProvider(providerSlug);
  const create = useCreateBooking();
  const navigate = useNavigate();
  const client = useQueryClient();

  const [step, setStep] = useState(0);
  const [timezone, setTimezone] = useState(visitorTimezone);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [details, setDetails] = useState<DetailsValues | null>(null);
  const [method, setMethod] = useState<PaymentMethod | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [honeypot, setHoneypot] = useState('');

  if (query.isPending) {
    return (
      <div className="container">
        <Loading />
      </div>
    );
  }
  const service = query.data?.services.find((s) => s.slug === serviceSlug);
  if (query.isError || !query.data || !service) {
    return (
      <div className="container">
        <Notice tone="danger" title="This session isn't available" live>
          <Link to={`/p/${providerSlug}`}>See the sessions you can book</Link>.
        </Notice>
      </div>
    );
  }
  const { provider } = query.data;
  const chosenMethod = method ?? service.payment_methods[0] ?? null;

  const goTo = (next: number) => {
    setProblem(null);
    if (next === 0) setServerErrors({});
    setStep(next);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const submit = () => {
    if (!slot || !details || !chosenMethod) return;
    create.mutate(
      {
        provider: provider.slug,
        service: service.slug,
        start: slot.start,
        payment_method: chosenMethod,
        customer: { name: details.name, email: details.email, phone: details.phone, timezone },
        answers: details.answers,
        website: honeypot,
      },
      {
        onSuccess: (created) => {
          client.setQueryData(keys.booking(created.ref), created.booking);
          navigate(`/b/${encodeURIComponent(created.ref)}?t=${encodeURIComponent(created.token)}`);
        },
        onError: (error) => {
          if (!(error instanceof ApiError))
            return setProblem('Something went wrong. Please try again.');
          if (SLOT_GONE.includes(error.code)) {
            setSlot(null);
            void client.invalidateQueries({ queryKey: ['slots', provider.slug, service.slug] });
            setStep(0);
            return setProblem(`${error.message} Please pick another time.`);
          }
          const fieldErrors = Object.fromEntries(
            Object.entries(error.fields).flatMap(([apiField, message]) => {
              const field = formFieldForApiField(apiField);
              return field ? [[field, message]] : [];
            }),
          );
          if (Object.keys(fieldErrors).length > 0) {
            setServerErrors(fieldErrors);
            setStep(1);
          }
          setProblem(error.message);
        },
      },
    );
  };

  return (
    <div className="container booking">
      <nav className="booking__crumb" aria-label="Breadcrumb">
        <Link to={`/p/${provider.slug}`}>← {provider.name}</Link>
      </nav>
      <header className="booking__head">
        <h1 className="booking__title">{service.title}</h1>
        <Stepper steps={STEPS} current={step} />
      </header>

      <div className="booking__layout">
        <section className="booking__step card" key={step}>
          {problem && (
            <Notice tone="danger" title="Not booked yet" live>
              {problem}
            </Notice>
          )}

          {step === 0 && (
            <>
              <StepTitle>Choose a time</StepTitle>
              <DateSlotPicker
                provider={provider.slug}
                service={service.slug}
                timezone={timezone}
                onTimezoneChange={setTimezone}
                selected={slot}
                onSelect={(chosen) => {
                  setSlot(chosen);
                  setServerErrors({});
                }}
              />
              <div className="step-actions">
                <Button onClick={() => goTo(1)} disabled={slot === null}>
                  Continue
                </Button>
              </div>
            </>
          )}

          {step === 1 && (
            <>
              <StepTitle>Your details</StepTitle>
              <DetailsForm
                questions={service.questions}
                initial={details}
                serverErrors={serverErrors}
                onBack={() => goTo(0)}
                onSubmit={(values) => {
                  setDetails(values);
                  setServerErrors({});
                  goTo(2);
                }}
              />
            </>
          )}

          {step === 2 && slot && details && (
            <>
              <StepTitle>Review & pay</StepTitle>
              <p className="muted">
                {service.title} with {provider.name}, {formatLongDateTime(slot.start, timezone)}.
                We'll email {details.email}.
              </p>

              {service.payment_methods.length > 1 ? (
                <fieldset className="methods">
                  <legend className="field__label">How would you like to pay?</legend>
                  {service.payment_methods.map((m) => (
                    <label key={m} className="method">
                      <input
                        type="radio"
                        name="method"
                        value={m}
                        checked={chosenMethod === m}
                        onChange={() => setMethod(m)}
                      />
                      <span>{METHOD_LABEL[m]}</span>
                    </label>
                  ))}
                </fieldset>
              ) : chosenMethod ? (
                <p className="method-single">
                  <span className="eyebrow">Payment</span> {METHOD_LABEL[chosenMethod]}
                </p>
              ) : (
                <Notice tone="warn" title="Payments aren't set up for this session yet">
                  Please contact {provider.name} to book.
                </Notice>
              )}

              {chosenMethod === 'upi' && (
                <Notice tone="info" title="What happens next">
                  Your time is held for 30 minutes while you pay. On the next page you'll see the
                  UPI details; pay {service.price_display} once, then enter the 12-digit UTR from
                  your UPI app.
                </Notice>
              )}
              {chosenMethod === 'razorpay_link' && (
                <Notice tone="info" title="What happens next">
                  Your time is held for 30 minutes. On the next page, pay {service.price_display} on
                  Razorpay's secure page; your booking is confirmed as soon as the payment goes
                  through.
                </Notice>
              )}
              {service.requires_approval && (
                <Notice tone="info" title="Needs approval">
                  {provider.name} will confirm this request by email, usually within a day.
                </Notice>
              )}

              <label className="honeypot" aria-hidden="true">
                Leave this empty
                <input
                  tabIndex={-1}
                  autoComplete="off"
                  value={honeypot}
                  onChange={(e) => setHoneypot(e.target.value)}
                />
              </label>

              <div className="step-actions">
                <Button variant="secondary" onClick={() => goTo(1)} disabled={create.isPending}>
                  Back
                </Button>
                <Button onClick={submit} disabled={create.isPending || !chosenMethod} large>
                  {create.isPending
                    ? 'Booking…'
                    : chosenMethod === 'upi' || chosenMethod === 'razorpay_link'
                      ? `Book and pay ${service.price_display}`
                      : service.requires_approval
                        ? 'Send request'
                        : 'Confirm booking'}
                </Button>
              </div>
            </>
          )}
        </section>

        <BookingSummary provider={provider} service={service} slot={slot} timezone={timezone} />
      </div>
    </div>
  );
}
