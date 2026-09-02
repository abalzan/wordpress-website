# Empregos — Agencies & Employment Permits (validated 2026-09-02)

Validated data for the /empregos/ expansion implemented on 2026-09-02.
Sources: each agency's/employer's own official website (title, contact page,
published phone/location) and the official DETE "Permits issued to companies"
statistics published on enterprise.gov.ie (publications for 2022, 2023, 2024
and 2025 — files `permits-issued-to-companies-YYYY.xlsx`). All external URLs
below were confirmed to resolve on the validation date.

## Editorial rules (binding for future maintenance)

1. **Historical permit evidence is never current sponsorship.** The frontend
   wording is "Há histórico oficial de Employment Permits para este
   empregador" — never "patrocina vistos", "oferece sponsorship", "candidate-se
   e consiga seu visto" or "esta vaga é elegível".
2. `verified` in `_employer_permit_status` = verified historical permit
   evidence in the official DETE statistics. Nothing more.
3. **Employers are never modelled as recruitment agencies** — separate CPT
   (`permit_employer`), separate section, separate wording.
4. No WRC/DETE reference numbers on the frontend (admin data only). No salary
   thresholds or permit quotas on cards (rules change; link to the official
   guidance instead: https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/).
5. No search, filters, maps, ratings, logos or sponsorship badges.
6. **Farm Solutions: on HOLD** until its WRC/licensing status/applicability is
   manually resolved. Do not publish it and do not describe it as unlicensed
   or illegitimate — it is simply not listed yet.
7. **Kepak** has NO permit-history badge until the exact DETE legal entity is
   matched and validated (DETE lists Kepak Clonee/Cork/Athleague/Longford/
   Kilbeggan Unlimited Company entities, 2023–2025 — the public brand must be
   mapped to one specific entity first). Seeded as `unverified`.
8. **Nua Healthcare** has historical DETE evidence but its own site states it
   is no longer recruiting internationally or sponsoring General Employment
   Permits. Seeded as `exception` — rendered in the "Importante" block, never
   with a generic verified badge; never implied as a current GEP contact.
9. **Liffey Meats**: public-facing name/site confirmed as "Liffey Meats"
   (https://liffeymeats.ie/ — site title "Home - Liffey Meats"; no rebrand and
   no dedicated careers page found). DETE legal entity is "Liffey Meats (Cavan)
   Unlimited Company" — the public name is what is shown.

## Agencies added (12) — official sites, phones/locations as published

| # | Agency | Website | Phone (verified) | Location | Job types |
|---|--------|---------|------------------|----------|-----------|
| 16 | TTM Healthcare Solutions | https://www.ttmhealthcare.com/ | +353 65 686 9300 | Nacional (Ennis, Co. Clare; Galway) | healthcare |
| 17 | Servisource | https://www.servisource.ie/ | — | Nacional | healthcare |
| 18 | Cpl | https://www.cpl.com/ | +353 1 614 6000 | Nacional (Dublin) | healthcare, office_admin, warehouse, logistics, factory_production |
| 19 | Access Healthcare | https://www.accesshealthcare.ie/ | +353 1 649 8500 | Nacional (Dublin) | healthcare |
| 20 | Gibbons Recruitment | https://gibbonsrecruitment.ie/ | — | Dublin | construction_labour, factory_production, warehouse, agriculture_seasonal |
| 21 | Ward Personnel | https://www.wardpersonnel.com/ | +353 1 539 0600 | Dublin, Cork, Athlone | construction_labour, general_operative |
| 22 | OSS Recruitment | https://www.ossrecruitment.ie/ | +353 1 460 5517 | Dublin | construction_labour, factory_production, general_operative |
| 23 | Hollilander | https://www.hollilander.ie/ | +353 1 204 0921 | Dublin | healthcare |
| 24 | Adecco Ireland | https://www.adecco.ie/ | — | Nacional | warehouse, logistics, office_admin, factory_production, hospitality |
| 25 | 3D Personnel | https://www.3dpersonnel.com/ | +353 1 513 3101 | Dublin, Cork, Galway | construction_labour |
| 26 | Collins McNicholas | https://www.collinsmcnicholas.ie/ | — | Galway, Cork, Sligo, Athlone | factory_production, office_admin, logistics |
| 27 | Sigmar Recruitment | https://www.sigmarrecruitment.com/ | +353 1 474 4600 | Galway, Dublin, Cork, Athlone | office_admin, warehouse, logistics, factory_production |

Notes:
- None of these 12 has a verified WRC licence number — none shows the
  "Licenciada" badge until one is confirmed (admin field `_agency_wrc_licence`
  stays empty; never guessed).
- Hollilander's official site is **hollilander.ie** (healthcare recruitment in
  Ireland); hollilander.com is a different business (study-abroad consultancy).
- Access Healthcare's official site is **accesshealthcare.ie** (Irish nurse/HCA
  recruitment); accesshealthcare.com is a different business (revenue-cycle
  management company).
- OSS's official public-facing brand is "OSS Recruitment" (ossrecruitment.ie).
- Domain `monaghanmushrooms.com` is no longer active; Monaghan Mushrooms'
  official site is monaghan.eu (title "Monaghan Mushrooms - A Natural Future").
- The `healthcare` canonical job-type key was added to
  `Conexao_Data_Model_Agency::job_types()` for this expansion.

## Permit-history employers (11 verified + 1 unverified + 1 exception)

Evidence years are the DETE publications in which the employer's name appears
in the "Permits issued to companies" tables. DETE legal-entity names stay in
the admin data (`_employer_notes`).

| Employer (public name) | Website | Careers | DETE legal entity (admin) | Years |
|------------------------|---------|---------|---------------------------|-------|
| Mowlam Healthcare | https://mowlamhealthcare.com/ | /careers/ | Mowlam Healthcare Services Unlimited Company | 2023–2025 |
| Resilience Healthcare | https://resiliencecare.ie/ | — | Resilience Healthcare Ltd | 2023–2025 |
| InisCare | https://www.iniscare.ie/ | /new-job/ | InisCare Limited | 2023–2025 |
| UL Hospitals Group (HSE Mid West) | https://www.hse.ie/eng/region/midwest/ | — | University Limerick Hospitals Group | 2023–2025 |
| Cork University Hospital | https://cuh.hse.ie/ | — | Cork University Hospital | 2023–2025 |
| University Hospital Galway | https://www.saolta.ie/ | — | Galway University Hospital | 2023–2025 |
| Rosderra Irish Meats | https://www.rosderra.ie/ | /careers/ | Rosderra Irish Meats Group UC | 2023–2025 |
| Dawn Meats | https://www.dawnmeats.com/ | /careers | Dawn Meats Ireland UC | 2023–2025 |
| ABP Food Group | https://abpfoodgroup.com/ | /careers/ | Anglo Beef Processors Ireland UC | 2023–2025 |
| Monaghan Mushrooms | https://www.monaghan.eu/ | /careers/ | Monaghan Mushrooms Ireland UC | 2023–2025 |
| Liffey Meats | https://liffeymeats.ie/ | — | Liffey Meats (Cavan) UC | 2023–2025 |
| Kepak | https://www.kepak.com/ | /careers/ | Kepak Clonee/Cork/Athleague/Longford/Kilbeggan UC — **entity not matched yet** | (badge withheld) |
| Nua Healthcare | https://www.nuahealthcare.ie/ | /careers/ | Nua Healthcare Services | 2023–2025 (exception: not currently sponsoring GEPs, per the company) |

## Reference (official)

- Employment permits guidance (single link shown on the page):
  https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/
- Statistics index:
  https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/statistics/
- Permits issued to companies (annual xlsx files): `permits-issued-to-companies-YYYY.xlsx`
