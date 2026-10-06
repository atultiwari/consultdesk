# Session pricing research (India, 2025–26)

Market research behind ConsultDesk's starter sessions (`api/src/Seed/ServiceTemplates.php`), which
the site setup wizard offers. All sources were accessed on 2026-10-06. **Unverified** means the figure
was seen only in a search summary or a third-party blog. Listing prices change often, so treat
individual listings as snapshots.

## Platform landscape and fees

| Platform | Provider's cost | Notes |
|---|---|---|
| Topmate | **10%** on sales via your own link, **20%** on marketplace sales | [topmate.io/pricing](https://topmate.io/pricing). Custom terms above ₹10L a month |
| MentorCruise | About 20% added on top of the mentor's price | [help.mentorcruise.com](https://help.mentorcruise.com/articles/what-fees-does-mentorcruise-take/). Monthly plans run $70–$450 |
| Unstop mentorship | 0% via a referral link, 20% via community (unverified) | Sessions at ₹49–₹999 |
| Preplaced | Long-term mentorship "from ₹2,500/month" (unverified) | [preplaced.dev](https://www.preplaced.dev/blog/how-much-does-a-career-mentor-cost) |
| Clarity.fm | 15% | Average 30-min call about $50 ([clarity.fm](https://clarity.fm/questions/6868/how-much-do-clarify-fm-pays-an-expert-for-answering-a-question)) |
| Superpeer | — | Shut down on 31 Dec 2024 ([Skillshare help](https://help.skillshare.com/hc/en-us/articles/32235980292877)) |
| Practo (doctors) | About 15–25% per paid online consult (unverified) | Consults typically ₹200–₹500 |
| Calendly + Razorpay | $10–16 per seat per month; Razorpay 2% + 18% GST on the fee | [razorpay.com](https://razorpay.com/blog/razorpay-payment-gateway-pricing-explained/) |

**Takeaway:** on a ₹999 session, self-hosting with standard Razorpay nets about ₹975 (2.36% effective
fee). Topmate's own-link plan nets ₹899 at most. That ₹75–₹200 a session is the case for
ConsultDesk.

## Findings by category

### Sampled listings (32 price points)

| # | Listing | Category | Min | Price (₹) | Notes |
|---|---|---|---|---|---|
| 1 | [Sri, "AI Doctor"](https://topmate.io/aidoctorsri/2261684) | Medical AI mentorship | 60 | 1,500 | AI coach |
| 2 | [MedPivot, Dr Preeti Malik](https://topmate.io/preeti_malik12/1486991) | Doctors moving to non-clinical careers | 30 | 1,500 | 120+ calls |
| 3 | [Doc Emm, Dr Piyush Malik](https://topmate.io/meet_dr_malik) | Breaking into health-tech | 30 | 599 (list price 999) | Clinician, IIT-D |
| 4 | same | Startup discovery / go-to-market | 30 | 1,299 (list price 5,299) | |
| 5 | same | 2-week go-to-market sprint | 240 total | 30,999 | Package |
| 6 | [Shiladitya Sil](https://topmate.io/shiladitya_sil/2000216) | MD/MDS thesis guidance | 45 | 499 | Clinical research mentor |
| 7–11 | [Tezan Sahu](https://topmate.io/tezan_sahu) | Discovery / resume review / career / data-science mock interview / package | 15 / 30 / 30 / 30 / multi | 1,299–1,499 / 1,799–2,099 / 2,799–3,299 / 3,599–4,299 / 8,999–10,395 | Senior Applied Scientist at Microsoft |
| 12–14 | [Durgesh Kumar](https://topmate.io/durgeshanalyst) | Free trial / resume / placement program | 45 / – / 30 sessions | 0 / 899 / 9,999 | Analytics founder |
| 15 | [datahub](https://topmate.io/datahub/2114373) | Resume review | 30 | 500 | |
| 16 | [Ketan Goel](https://topmate.io/ketan_goel_analytics_expert/148371) | Resume review | 30 | 799 (list price 2,500) | 6+ years |
| 17 | [Mazher Khan](https://topmate.io/mazher_khan/462729) | Career mentorship | 60 | 1,549 (list price 2,499) | 8+ years |
| 18 | [PremSai](https://topmate.io/premsaisahoo/2077933) | GenAI/LLM mentorship | 30 | 500 | 4+ years |
| 19 | [Vikash Mishra](https://topmate.io/vikash_mishra20/2227927) | LinkedIn review | 30 | 499 (list price 999) | Early career |
| 20 | [Prashant Priyadarshi](https://topmate.io/prashant_priyadarshi/665067) | Low-level design mock interview | 90 | 3,200 (list price 4,800) | 12+ years |
| 21 | [Paritosh Anand](https://topmate.io/paritosh_anand/1419092) | Pitch-deck review | 60 | 1,299 | IIM-A, Startup India mentor |
| 22 | [PW Disha](https://www.pw.live/neet/exams/one-on-one-mentorship-program-for-neet-exam) | NEET-UG mentor call | call | 149 | Mass market |
| 23 | [neetmentor.co.in](https://www.neetmentor.co.in/NEET-PG) | NEET-PG counselling package | season | 14,999–19,999 | 2024 page |
| 24–25 | [USMLE Sarthi](https://www.usmlesarthi.com/store/c23/usmlementorship) | USMLE/IMG mentorship | 60 / 120 | $60 / $95 | US-facing |
| 26 | [UrbanPro 2025](https://www.urbanpro.com/urbanpro-in-numbers-2025) | School tutoring, 1:1 | 60 | 410 average (Class 10: 427, Class 12: 414) | Platform data |
| 27 | [Yocket/Vedantu](https://prep.yocket.com/ielts/ielts-coaching-fees) | IELTS/English 1:1 | 60 | 700–2,500 | |
| 28 | [TherapyRoute 2025](https://therapyroute.com/article/how-much-does-therapy-cost-in-india-2025-by-therapyroute) | Psychologist / counsellor | ~50 | 800–4,025 | |
| 29 | [docgenie](https://docgenie.in/online-doctor-consultation/dietician) | Dietitian | session | 300–1,200 | |
| 30 | [LawRato via YourStory](https://yourstory.com/2016/02/lawrato) | Lawyer phone consult | 30 | 500 | Old (2016) |
| 31 | [Metropolis](https://metropolisindia.com/parameter/second-opinion) | Histopathology second opinion | — | 2,500–2,650 | Laboratory service |
| 32 | [flexingit](https://www.flexingit.com/feebee/freelance-data_management_biostatistics_documentation-fees-id-305/) | Freelance biostatistics | per day | 4,000 / 7,000 / 22,700 | low / average / high |

Strike-through "discounts" are everywhere on Topmate (listings 3, 4, 16, 17, 19 and 20). We used real
selling prices, and the starter sessions show real prices only. Durations cluster at 30 and
60 minutes; 15 minutes is used for discovery calls and 45 for thesis or career sessions.

### Ranges by category (INR, actual selling price)

| Category | Low | Median | High | Usual length | Confidence |
|---|---|---|---|---|---|
| Medical AI / digital-health mentorship | 500 | ~1,300–1,500 | 1,500 per 30 min; packages to 31k | 30–60 | Medium |
| Thesis / research guidance (call) | 499 per 45 min | — | — | 45–60 | **Thin** |
| Biostatistics (project) | 4,000/day | 7,000/day | 22,700/day | project | Medium; many vendors are ghostwriters, so excluded |
| Manuscript review | — | — | US/EU services $149–$1,800 | written + call | **No Indian 1:1 data** |
| Medical careers (NEET-PG, USMLE, abroad) | 149 | ~1,500 per 30 min | ~5,000/hr (USD-priced) | 30–60 | Medium |
| ML code / project review | 500 | — | 3,200–4,299 (mock-interview proxy) | 60–90 | **Thin** |
| Startup / product advice | 1,299 | 1,299 | 30,999 (sprint) | 30–60 | Low–medium |
| Tutoring (school / college) | ~300 | 410–430 an hour | 1,500 an hour | 60 | High |
| Career / placement mentoring | 0 | ~1,500 | 3,299 per 30 min | 30–60 | Medium |
| Interview prep (tech / data / PM) | 3,200 | ~3,600 | 4,299 | 30–90 | Medium |
| CV / LinkedIn review | 499 | ~800 | 2,099 | 30 | High |
| Language / skill coaching | 700 | ~1,200 | 2,500 an hour | 60 | Medium |
| Professional consults (therapy, diet, legal) | 300 | 800–1,500 | 4,600 | 30–50 | Medium |

### Institutional honoraria

- **UGC guest faculty:** ₹1,500 per lecture, up to ₹50,000 a month.
- **Kerala DTE:** ₹1,500 an hour (up to ₹7,500 a day); ₹2,000 an hour for IIT/IISc-tier experts.
- **AICTE ATAL FDP 2025–26:** ₹5,000 per session, ₹10,000 for overseas experts (unverified in the
  primary PDF).
- **AIIMS Jammu (visiting doctors):** ₹5,000 a day plus travel and lodging.
- **Corporate AI workshops:** ₹1–4 lakh a day (vendor blogs).
- **Many medical-college CMEs** pay only travel allowance plus a memento.

### Doctors: teleconsultation is not what these sessions are

Under the **Telemedicine Practice Guidelines (2020)**, only registered medical practitioners may
consult patients remotely, and they set rules for first consults and prescriptions. ConsultDesk's
medical starter sessions are **educational and career guidance, and review of de-identified
research**. They say so, and they never ask for patient-identifiable data.

## Pricing mechanics

- **GST:** private coaching and tutoring attract 18%; the "education" exemption covers only
  recognised institutions. Registration is required once service turnover crosses **₹20 lakh**
  (₹10 lakh in special-category states). Most solo educators are below that and quote GST-free
  prices; an academy may not be. Check with a CA.
- **Razorpay:** standard rate 2% + 18% GST on the fee, no setup or annual charge. Direct UPI to your
  own UPI ID is free but has to be checked by hand (the UTR flow).
- **Charm pricing:** almost every listing ends in 99 or 49 (₹499, ₹999, ₹1,499, ₹1,999).
  Institutional quotes use round numbers because they end up on purchase orders.
- **Willingness to pay:**
  - students: ₹149–₹799;
  - working professionals: ₹1,299–₹4,299 per 30–60 min;
  - institutions: fixed norms (₹1,500–₹5,000 per session in government settings), or corporate
    budgets.
- **Free intro calls** are common for converting cold traffic. Senior mentors often charge even for
  a 15-minute discovery call.
- **Packages** run 15–25% below per-session prices. ConsultDesk books single sessions for now;
  bundles are on the "later" list in docs/PLAN.md.

### International comparison

| Category | International (USD) |
|---|---|
| Mentorship (MentorCruise) | $120–450 a month for about 2 calls plus chat |
| Expert calls (Clarity) | About $50 per 30 min |
| PM / tech mock interviews | $69–399, median $149 |
| US university biostatistics | $70–310 an hour |
| USMLE mentors | $60–120 an hour |

At about ₹85–90 to the dollar, a ₹1,500 session is about $17. At India's purchasing-power rate
(roughly ₹20–22 per international dollar, approximate) it is worth about $70. Educators with
overseas clients can reasonably charge in USD; exports of services are zero-rated for GST with a
Letter of Undertaking.

## What the starter sessions use

The setup wizard offers three sets. Each session shows a suggested price and the band it comes from,
and the owner can change title, length and price before adding it.

1. **Any teacher or consultant:**
   - free 15-min intro;
   - quick clarity call, 30 min, ₹499;
   - one-to-one session, 60 min, ₹999;
   - CV + LinkedIn review, 30 min, ₹499;
   - mock interview, 60 min, ₹1,499;
   - tutoring hour, ₹449;
   - career roadmap, 45 min, ₹799.

   These are the early-career prices; established providers in the same markets charge about
   2.5–3× as much.
2. **Medical AI, research and careers:**
   - free intro;
   - medical-AI career roadmap, 45 min, ₹999;
   - thesis/protocol design clinic, 60 min, ₹1,499 (approval);
   - statistics & results review, 60 min, ₹1,999 (approval);
   - manuscript pre-submission review, 45 min, ₹2,999 (approval);
   - ML project/code review, 60 min, ₹1,499;
   - medical career guidance, 30 min, ₹699;
   - health-tech product/startup advice, 60 min, ₹4,999 (approval).

   These are the student prices, with the professional price shown alongside (about 2×). The
   sessions that need approval let the teacher check scope and decline ghostwriting.
3. **Institutions:**
   - invited talk / guest lecture;
   - hands-on workshop;
   - FDP / multi-day programme.

   All three are free request forms that need approval, because the fee is agreed afterwards. The
   suggested floors are an institution's own norm (₹1,500–₹5,000 per session) or ₹5,000+ for a talk,
   and ₹15,000 for a half day or ₹25,000 for a full day of workshop at private institutions.

**Gaps to revisit with direct quotes from practitioners:** Indian hourly rates for biostatistics and
research consulting, dedicated ML code-review listings, manuscript-review sessions, and medical
college CME honoraria. The prices of medical sessions 4–6 are therefore provisional.
