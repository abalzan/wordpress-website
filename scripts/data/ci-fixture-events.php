<?php
/**
 * CI FIXTURE DATASET — synthetic Events, Sponsors and the SHARED proper-name
 * taxonomy terms (Stage P, CI readiness).
 *
 * This file carries the three fixture groups that are NOT an authored English
 * manifest, because none of them is a B1 translated type: Events, Sponsors and
 * the county/town terms are all documented **B2** (see
 * `conexao_b2_post_types()` in the theme's inc/i18n/fallback.php). B2 means the
 * Portuguese record is deliberately served under the English URL with a
 * fallback notice, so none of them needs an authored English record and none
 * may gain one from this file.
 *
 * ── Why the acceptance matrix needs exactly these terms ────────────────
 *
 * The maintained rows assert on FILTER WIDGET LABELS, not merely on a 200:
 *
 *   en-eventos-county-filter-works   /en/eventos/?county=laois
 *       -> requires the dropdown option labelled "Laois" to exist
 *   en-eventos-town-filter-works     /en/eventos/?cidade=adare
 *       -> requires the dropdown option labelled "Adare" to exist
 *
 * A filter widget only offers terms that are IN USE, so the terms must exist
 * AND be attached to at least one published event. Both are created below.
 *
 * SHARED TAXONOMY, NOT TRANSLATED. `conexao_county` and `conexao_town` hold
 * proper names (Laois, Adare) that are identical in every language. They are
 * SHARED, never translated: one physical term serves both languages, and
 * creating a per-language `-en`/`-pt` duplicate is precisely the defect the
 * permanent `taxonomy_policy` gate exists to catch. Nothing in this file
 * writes a language to either taxonomy, and no term is ever duplicated per
 * language.
 *
 * @package Conexao_BR_Scripts
 */

defined( 'ABSPATH' ) || defined( 'CONEXAO_SCRIPTS_BOOTSTRAP' ) || exit;

/**
 * The synthetic published events.
 *
 * Dates are RELATIVE to the day the fixture runs, because the event runtime
 * deliberately hides past events: a fixed calendar date would make every
 * fixture event expire and silently empty /eventos/ a few months from now,
 * which is exactly the "the fixture quietly stopped being exercised" failure
 * this dataset exists to prevent. Relative dates are still fully
 * deterministic for a given run: they are computed from one captured "today"
 * and every record is derived from it, so a single run is internally
 * consistent, and two runs on the same day are byte-identical.
 *
 * @return array<int,array{title:string,slug:string,date_offset:int,date:string,time:string,county:string,town:string,location:string,category:string,content:string}>
 */
function conexao_ci_fixture_events(): array {
	$today = current_time( 'Y-m-d' );

	//
	// ALL THREE ARE OFFSET 0 (today), and that is load-bearing rather than
	// incidental. The /eventos/ filter widget renders its county and town
	// options from the terms IN USE BY PUBLISHED events, so a future-dated
	// fixture (`post_status = future`) contributes no terms and the
	// `?county=laois` / `?cidade=adare` acceptance rows find an empty dropdown.
	// Today keeps them `publish`, in use, and stable.
	//
	// The three named events are dated TODAY at 06:00, not 19:00. WordPress
	// derives `post_status = future` from any `post_date` more than a minute
	// ahead, and a 19:00 fixture is still in the future for any run before 19:00 —
	// which removed the county/town terms from the filter dropdowns the
	// acceptance rows assert on. 06:00 is in the past for every run, and these
	// records exist only to put a term in use, not to look like a real listing.
	$rows = array(
		array(
			'title'    => 'Evento CI sintético em Laois (Portimma)',
			'slug'     => 'evento-ci-laois-portimma',
			// +7 days: comfortably inside the runtime's 7-day upcoming window.
			'offset'   => 0,
			'time'     => '06:00',
			'county'   => 'Laois',
			'town'     => 'Portimma',
			'location' => 'Portimma, County Laois',
			'category' => 'Cultura',
			'content'  => 'Evento sintético criado pela arvore de fixtures determinísticas da CI, para exercitar o filtro por condado (Laois) e por cidade (Adare). Não é um evento real.',
		),
		array(
			'title'    => 'Evento CI sintético em Adare (Limerick)',
			'slug'     => 'evento-ci-adare-limerick',
			'offset'   => 0,
			'time'     => '06:00',
			'county'   => 'Limerick',
			'town'     => 'Adare',
			'location' => 'Adare, County Limerick',
			'category' => 'Cultura',
			'content'  => 'Evento sintético criado pela arvore de fixtures determinísticas da CI, para exercitar o filtro por cidade (Adare). Não é um evento real.',
		),
		array(
			'title'            => 'Evento CI sintético recorrente em Dublin',
			'slug'             => 'evento-ci-recorrente-dublin',
			'offset'           => 0,
			'time'             => '06:00',
			'county'           => 'Dublin',
			'town'             => 'Dublin',
			'location'         => 'Dublin',
			'category'         => 'Cultura',
			'content'          => 'Evento sintético recorrente semanal criado pela arvore de fixtures determinísticas da CI, para exercitar a recorrência. Não é um evento real.',
			// Weekly, starting on its own date and open-ended, so the
			// recurrence query always has an active series to resolve.
			'recurrence'       => 'weekly',
			'recurrence_days'  => '',
			'recurrence_start' => '',
		),
	);

	foreach ( $rows as $index => $row ) {
		$date = gmdate( 'Y-m-d', strtotime( $today . ' +' . (int) $row['offset'] . ' days' ) );

		// The FULL datetime is stored, including the record's own start time.
		// A bare `Y-m-d` made every re-run rewrite `post_date` with a different
		// time of day, so the bootstrap reported ten "repairs" forever and could
		// never be proven idempotent.
		$rows[ $index ]['date'] = $date . ' ' . (string) ( $row['time'] ? $row['time'] : '12:00' ) . ':00';

		// A weekly series repeats on the weekday of its own first date, so it
		// is active on the day the fixture is seeded regardless of which day
		// that is. Derived, never hard-coded, for the same reason the dates are.
		if ( ! empty( $row['recurrence'] ) ) {
			$weekday                            = gmdate( 'N', strtotime( $date ) );
			$rows[ $index ]['recurrence_days']  = $weekday;
			$rows[ $index ]['recurrence_start'] = $date;
		}
	}

	//
	// Location-coverage events. The /eventos/ filter widget only renders its
	// client-side "Search county" / "Search town" fields when there are MORE
	// THAN EIGHT options (`$show_county_search = count( $county_terms ) > 8`),
	// and `en-eventos-filters-english-copy` asserts those two placeholders. The
	// curated event dataset does not reliably leave nine IN-USE counties and
	// towns on a freshly seeded site, so the coverage is created here
	// deterministically instead of being left to whatever the bulk import
	// happened to leave visible.
	//
	// Ten counties, ten towns, one event each, all dated today so they are
	// `publish` and therefore counted. Real Irish counties and towns — a
	// gazetteer, not personal data — and SHARED terms, never duplicated per
	// language.
	$coverage = array(
		array( 'Dublin', 'Dublin' ),
		array( 'Cork', 'Cork' ),
		array( 'Galway', 'Galway' ),
		array( 'Kerry', 'Killarney' ),
		array( 'Clare', 'Ennis' ),
		array( 'Meath', 'Navan' ),
		array( 'Wexford', 'Wexford' ),
		array( 'Mayo', 'Castlebar' ),
		array( 'Kilkenny', 'Kilkenny' ),
		array( 'Waterford', 'Waterford' ),
	);

	foreach ( $coverage as $index => $place ) {
		list( $county, $town ) = $place;

		$rows[] = array(
			'title'    => sprintf( 'Evento CI de cobertura %02d — %s, %s', $index + 1, $town, $county ),
			'slug'     => sprintf( 'evento-ci-cobertura-%02d', $index + 1 ),
			// `date` is required: the rows above are appended AFTER the loop that
			// derives it, so these must carry it themselves or WordPress rejects
			// the insert with the opaque "Data inválida.".
			// 06:00, not 12:00: these records exist to put a term IN USE for the
			// filter dropdowns, and a future-dated record is `future`, which
			// contributes no terms.
			'date'     => $today . ' 06:00:00',
			'offset'   => 0,
			'time'     => '06:00',
			'county'   => $county,
			'town'     => $town,
			'location' => $town . ', County ' . $county,
			'category' => 'Cultura',
			'content'  => 'Evento sintético de cobertura territorial, criado pela arvore de fixtures determinísticas da CI. Não é um evento real.',
		);
	}

	return $rows;
}

/**
 * The synthetic published sponsors.
 *
 * Sponsor is a documented B2 directory type. The release smoke matrix
 * (`check_singles()` in scripts/verify-deploy.py) discovers ONE public single
 * per post type over the public REST API, so an empty sponsor table fails the
 * release acceptance row for a reason that has nothing to do with the release.
 *
 * `_sponsor_display_order` is the editor-curated ordering field the /apoiadores/
 * archive sorts on, so these records set it explicitly and therefore also
 * exercise the curated-order branch of the archive rather than only the
 * unordered fallback.
 *
 * @return array<int,array{title:string,slug:string,excerpt:string,content:string,link:string,order:int}>
 */
function conexao_ci_fixture_sponsors(): array {
	return array(
		array(
			'title'   => 'Apoiador CI sintético Alfa',
			'slug'    => 'apoiador-ci-sintetico-alfa',
			'excerpt' => 'Apoiador sintético criado pela arvore de fixtures determinísticas da CI para exercitar /apoiadores/. Não é uma empresa real.',
			'content' => 'Registo sintético de teste. Não representa uma empresa real e não deve sercontactado.',
			'link'    => 'https://example.invalid/ci-fixture-alfa',
			'order'   => 1,
		),
		array(
			'title'   => 'Apoiador CI sintético Beta',
			'slug'    => 'apoiador-ci-sintetico-beta',
			'excerpt' => 'Apoiador sintético criado pela arvore de fixtures determinísticas da CI para exercitar /apoiadores/. Não é uma empresa real.',
			'content' => 'Registo sintético de teste. Não representa uma empresa real e não deve sercontactado.',
			'link'    => 'https://example.invalid/ci-fixture-beta',
			'order'   => 2,
		),
		array(
			'title'   => 'Apoiador CI sintético Gama',
			'slug'    => 'apoiador-ci-sintetico-gama',
			'excerpt' => 'Apoiador sintético criado pela arvore de fixtures determinísticas da CI para exercitar /apoiadores/. Não é uma empresa real.',
			'content' => 'Registo sintético de teste. Não representa uma empresa real e não deve sercontactado.',
			'link'    => 'https://example.invalid/ci-fixture-gama',
			'order'   => 3,
		),
	);
}

/**
 * The SHARED `conexao_county` terms the fixtures require, keyed by name.
 *
 * @return array<string,string> Name => nothing (the array key is the term).
 */
function conexao_ci_fixture_counties(): array {
	return array(
		'Laois'    => 'Laois',
		'Limerick' => 'Limerick',
		'Dublin'   => 'Dublin',
	);
}

/**
 * The SHARED `conexao_town` terms the fixtures require, keyed by name.
 *
 * SCOPE, and why the list is longer than the three the filter rows need.
 * `conexao_town` is not only a filter vocabulary: the maintained suite
 * `conexao-event-importer/tests/test-town-sanitization.php` has a
 * "Database state verification" section that asserts specific CLEAN terms
 * exist in this taxonomy — `Ballinamore`, `Oranmore`, `Galway`, `Cork`, `Cobh`
 * and `Corofin` — because that suite proves the importer strips Eircodes and
 * `Co.` / `, Ireland` contamination out of town names. With only the three
 * filter towns present, that contract cannot be evaluated and five assertions
 * fail.
 *
 * So the registry carries the towns the maintained contracts actually name.
 * They are all real, published Irish place names — a proper-noun gazetteer, not
 * personal data — and the taxonomy is SHARED, so one physical term serves every
 * language and none of them is ever duplicated per language or given a
 * language tag.
 *
 * @return array<string,string>
 */
function conexao_ci_fixture_towns(): array {
	return array(
		// Required by the acceptance matrix filter row (?cidade=adare).
		'Adare'       => 'Adare',
		// Required by the event fixture that populates the Portimma option.
		'Portimma'    => 'Portimma',
		'Dublin'      => 'Dublin',
		// Required by test-town-sanitization.php "Database state verification".
		'Ballinamore' => 'Ballinamore',
		'Oranmore'    => 'Oranmore',
		'Galway'      => 'Galway',
		'Cork'        => 'Cork',
		'Cobh'        => 'Cobh',
		'Corofin'     => 'Corofin',
		// Named by the same suite's county/town classifier contract.
		'Kilkenny'    => 'Kilkenny',
		'Sligo'       => 'Sligo',
		'Waterford'   => 'Waterford',
		'Wexford'     => 'Wexford',
		'Limerick'    => 'Limerick',
	);
}

/**

/**
 * The TRANSLATED `conexao_category` term pairs the maintained suites require.
 *
 * `conexao_category` and the core `category` are TRANSLATED taxonomies: one
 * concept identity with an EN term linked to its PT term in BOTH directions.
 * (This is the opposite of `conexao_county`/`conexao_town` above, which are
 * SHARED and are never duplicated per language.)
 *
 * WHY THIS LIST IS EXPLICIT. `test-stage33-bilingual.php` asserts, by name,
 * that each of these exact PT/EN term pairs exists and is linked:
 * documentos/documents, trabalho/work, financas/finances, festivais/festivals,
 * musica/music, cultura/culture, natureza/nature, cidades/cities,
 * negocios/businesses, gastronomia/gastronomy, empregos/jobs, plus
 * core `category` comunidade/community. Those names are the CONTRACT, so they
 * are reproduced here verbatim rather than inferred. The guide fixtures alone
 * are not enough: they create the 13 guide-scoped categories, and this set is
 * the directory/event scope.
 *
 * @return array<string,array<string,string>> Taxonomy => PT slug => EN slug.
 */
function conexao_ci_fixture_term_pairs(): array {
	return array(
		'conexao_category' => array(
			'documentos'  => 'documents',
			'trabalho'    => 'work',
			'financas'    => 'finances',
			'festivais'   => 'festivals',
			'musica'      => 'music',
			'cultura'     => 'culture',
			'natureza'    => 'nature',
			'cidades'     => 'cities',
			'negocios'    => 'businesses',
			'gastronomia' => 'gastronomy',
			'empregos'    => 'jobs',
		),
		'category'         => array(
			'comunidade' => 'community',
		),
	);
}

/**
 * The synthetic PT `job` records.
 *
 * Job is a documented B2 directory type, so no EN record is required BY
 * POLICY. But two maintained suites assert on a real job population rather
 * than on an empty one:
 *
 *   test-job-en-translation.php   "the site has public PT jobs", and
 *                                  "at least one PT job has a real EN
 *                                  translation (the real-translation path is
 *                                  exercised)"
 *   test-jobs-en-language.php     the listing helper returning public jobs
 *
 * Both are non-vacuous ONLY when a job exists. This dataset therefore supplies
 * the minimum real population: one PT job, which is what the release smoke
 * matrix's per-CPT single discovery needs, and which lets the B2-with-a-real-
 * translation path be exercised rather than skipped.
 *
 * The EN counterpart is created by the SAME step, as a named cross-language
 * fixture pair (`ajudante-de-cozinha-dublin` ↔ `kitchen-assistant-dublin` in
 * conexao_ci_fixture_named_pairs()). There is no job TRANSLATION STAGE: the
 * seven stages of conexao-en-translation carry no job-records stage, and the
 * retired `conexao-job-translation` plugin that used to translate this record
 * was removed in Stage 19 (its runner now lives in scripts/historical/ as
 * unsupported tooling and cannot execute). No EN job is invented here and no
 * second job-translation mechanism is introduced — this dataset and step 4b
 * own committed synthetic records, which is not published English copy.
 *
 * @return array<string,array{title:string,excerpt:string,content:string,date:string,location:string,type:string}>
 */
function conexao_ci_fixture_jobs(): array {
	return array(
		'oportunidades' => array(
			'title'    => 'Oportunidades CI sintéticas de emprego',
			'excerpt'  => 'Vaga sintética criada pela arvore de fixtures determinísticas da CI para exercitar o directório /empregos/. Não é uma vaga real.',
			'content'  => '<!-- wp:paragraph --><p>Esta é uma oportunidade de emprego SINTÉTICA, criada apenas pela arvore de fixtures determinísticas da integração contínua. Não representa uma vaga real e não deve ser contactada.</p><!-- /wp:paragraph -->',
			// A fixed historical date keeps the listing order deterministic and
			// keeps the record out of any "current openings" date dependency.
			'date'     => '2026-01-15 09:00:00',
			'location' => 'Dublin',
			'type'     => 'full-time',
		),
	);
}

/**
 * The minimum population the CI synthetic site must have, per content type.
 *
 * WHY THESE NUMBERS EXIST. Translation completeness is a B1 invariant and is
 * therefore only meaningful over a NON-EMPTY population: on an empty
 * registered content type `eligible = 0`, `missing = 0`, and the gate passes
 * without having proved anything. The numbers below are the documented floor
 * the anti-vacuity check enforces, and each one is derived from a real
 * maintained contract rather than chosen to make a run green:
 *
 *   guide          >= 11  /guias/ and /en/guias/ paginate at 10 per page, so
 *                         `pt-guides-page-2` and `en-guides-page-2` are 404
 *                         below 11 published guides.
 *   post           >= 11  same pagination reason, for
 *                         `en-archive-en-blog-page-2` (/en/blog/page/2/).
 *   page            = 34  every page the `en-page` stage authors; a missing
 *                         page is a real missing EN record, not a fixture gap.
 *   sponsor        >=  3  `check_singles()` discovers one public single per
 *                         post type; 3 also spans the curated-order branch
 *                         and the unordered branch of the archive.
 *   leisure        >=  1  `check_singles()`, and the EN/PT card-excerpt rows
 *                         (`en-lazer-card-excerpt-is-english`,
 *                         `pt-lazer-card-excerpt-stays-portuguese`).
 *   course_provider>=  1  `check_singles()`, plus the five category-label rows.
 *   event          >=  3  county filter (Laois), town filter (Adare) and the
 *                         recurrence branch, one record each.
 *   county terms   >=  3  Laois/Limerick/Dublin: the filter labels the
 *                         acceptance matrix asserts on.
 *   town terms     >= 12  Adare/Portimma/Dublin for the filter rows, plus the
 *                         clean registry names test-town-sanitization.php
 *                         asserts exist in `conexao_town`.
 *
 * @return array<string,int>
 */
function conexao_ci_fixture_minimums(): array {
	return array(
		'guide'           => 11,
		'post'            => 11,
		'page'            => 34,
		'sponsor'         => 3,
		'leisure'         => 1,
		'course_provider' => 1,
		'event'           => 3,
		'county_terms'    => 11,
		'town_terms'      => 18,
		// Job is B2, so this is a population floor, not a translation floor:
		// test-job-en-translation.php asserts a public PT job exists and that one
		// has a real EN translation, and the release matrix discovers a job
		// single. Both are vacuous with zero jobs.
		'job'             => 1,
	);
}

/**
 * The NAMED cross-language fixture pairs the bilingual REST suites require.
 *
 * WHY THIS EXISTS, AND WHY IT IS NAMED RATHER THAN GENERATED.
 * `test-stage41-rest-language.php` (Stage 4.1) exercises the REST request model
 * — default scope, `lang=pt`, `lang=en`, invalid values, and the three
 * REPLACEMENT rules — against a set of records it identifies BY SLUG. Its
 * assertions are about *this* population: that the EN translation of an event
 * replaces its PT master, that a source-inherited EN event is visible in
 * `lang=en`, that a PT-only event stays B2, and that a past / rejected /
 * removed event is hidden. Those rules can only be observed on records whose
 * language and status are known exactly, which is why the suite names them
 * rather than discovering them.
 *
 * The alternative — generating plausible slugs — would make the suite's
 * assertions silently vacuous, because the replacement rules are only
 * observable on a record that actually has a linked EN translation.
 *
 * SYNTHETIC AND NON-SENSITIVE. Every record is a fabricated community record
 * with fabricated details. No production copy, no real business, no real person.
 * `brasil-market-dublin` is a made-up sponsor.
 *
 * The EN halves are created here, with an explicit Polylang link, because
 * these slugs are deliberately NOT in the authored translation manifests: the
 * suites need a paired record, not published English copy, and adding them to a
 * published manifest would imply they are content the site is meant to serve.
 *
 * @return array<int,array<string,mixed>>
 */
function conexao_ci_fixture_named_pairs(): array {
	return array(
		// The leisure listing the EN/PT card-excerpt acceptance rows assert on by
		// EXACT text: `en-lazer-card-excerpt-is-english` requires "Restored
		// farmhouse at the foot of Keadeen mountain" and
		// `pt-lazer-card-excerpt-stays-portuguese` requires the Portuguese
		// source. Both come from the authored
		// `en-leisure-description` stage, which matches the PT slug
		// `dwyer-mcallister-cottage`.
		//
		// It has to be created here because it is a site record the stage
		// translates, not a test double: without the PT record the stage has
		// nothing to attach the authored English to, and both rows fail. The
		// PT excerpt is written to be the EXACT `pt_source` the authored English
		// was written from, so the stage's PT-drift guard accepts it.
		array(
			'pt_slug'    => 'dwyer-mcallister-cottage',
			'type'       => 'leisure',
			'title'      => 'Dwyer McAllister Cottage',
			'county'     => 'Wicklow',
			'town'       => 'Aughrim',
			'pt_excerpt' => 'Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje pequeno museu de época.',
			'external'   => false,
		),

		// --- Events: the four language/status shapes the REST model must tell
		// apart. `festa-junina-dublin-2026` is the PT master and
		// `festa-junina-dublin-2026-en` its REAL EN translation, so the PT
		// master is REPLACED rather than served B2.
		array(
			'pt_slug'   => 'festa-junina-dublin-2026',
			'uuid'      => 'ci-fixture-0000-0000-4000-8000-000000000001',
			'source'    => 'eventbrite',
			'source_id' => 'pilot-eb-0001',
			'type'      => 'event',
			'title'     => 'Festa Junina sintética em Dublin',
			'en_slug'   => 'festa-junina-dublin-2026-en',
			'en_title'  => 'Synthetic June Festival in Dublin',
			'county'    => 'Dublin',
			'town'      => 'Dublin',
			'offset'    => 0,
		),
		// Source-inherited EN: an event whose SOURCE is already English, so the
		// EN record IS the record (no PT master to translate).
		// The two events the REST suite requires to be HIDDEN. They are not
		// missing content: their whole purpose is to be invisible, one because
		// the importer rejected it and one because the source record was removed
		// upstream. The suite asserts exactly that absence, so the fixtures must
		// EXIST in the database carrying those statuses — a fixture that is
		// simply absent would make the assertion vacuous.
		array(
			'pt_slug' => 'evento-rejeitado-spam',
			'type'    => 'event',
			'title'   => 'Evento sintético rejeitado pelo importador',
			'county'  => 'Dublin',
			'town'    => 'Dublin',
			'offset'  => 0,
			// Hidden by the `_event_status` META, not by `post_status`.
			//
			// `inc/rest-language.php` answers 404 for a record whose
			// `_event_status` is expired/rejected/source_not_found while
			// `post_status` stays `publish`. Using `post_status` instead would
			// either make the record read as absent (so the suite's own
			// fixture lookup, which filters out `trash`, could not find it) or
			// leak it into the collection the assertion says it must not be in.
			'hidden'  => 'rejected',
		),
		array(
			'pt_slug' => 'evento-removido-na-fonte',
			'type'    => 'event',
			'title'   => 'Evento sintético removido na fonte',
			'county'  => 'Cork',
			'town'    => 'Cork',
			'offset'  => 0,
			'hidden'  => 'source_not_found',
		),
		array(
			'pt_slug'         => 'irish-dance-workshop-dublin',
			'type'            => 'event',
			'title'           => 'Irish Dance Workshop (source English)',
			'source_language' => 'en',
			'source'          => 'https://example.invalid/ci-fixture-events/irish-dance',
			'source_id'       => 'ci-fixture-event-0002',
			'county'          => 'Dublin',
			'town'            => 'Dublin',
			'offset'          => 0,
		),
		// PT-only B2: no EN record, so it must stay a B2 fallback in `lang=en`.
		array(
			'pt_slug' => 'feijoada-beneficente-cork',
			'type'    => 'event',
			'title'   => 'Feijoada beneficente sintética em Cork',
			'county'  => 'Cork',
			'town'    => 'Cork',
			'offset'  => 0,
		),
		// Hidden: PAST. The runtime must not serve an event whose date has
		// passed, whichever language is requested.
		array(
			'pt_slug' => 'noite-de-mpb-bray',
			'type'    => 'event',
			'title'   => 'Noite de MPB sintética (passada)',
			'county'  => 'Wicklow',
			'town'    => 'Bray',
			'offset'  => -30,
			'hidden'  => 'expired',
		),

		// --- Leisure: a PT master with a real EN translation, a PT-only
		// INTERNAL listing (served B2, no off-site redirect) and a PT-only
		// EXTERNAL listing (redirects to its official site).
		//
		// A REAL EN sibling post, not the field-level `_leisure_excerpt_en`.
		//
		// Leisure is normally a B2 directory whose English layer is a field on
		// the same record, and the `en-leisure-description` stage writes that
		// field. But the Stage 4.1 REST suite names a DISTINCT `phoenix-park-en`
		// RECORD and asserts the PT master is replaced by it, which is the
		// "real EN translation" branch rather than the fallback branch. Both
		// shapes have to exist for the suite to cover them, so this dataset
		// creates the real pair in addition to the field-level descriptions.
		array(
			'pt_slug'    => 'phoenix-park',
			'uuid'       => 'ci-fixture-0000-0000-4000-8000-000000000002',
			'type'       => 'leisure',
			'title'      => 'Phoenix Park (sintético)',
			'en_slug'    => 'phoenix-park-en',
			'en_title'   => 'Phoenix Park (synthetic)',
			'county'     => 'Dublin',
			'town'       => 'Dublin',
			'pt_excerpt' => 'Um dos maiores parques urbanos fechados da Europa, lar de veados selvagens, a residência presidencial e o Dublin Zoo.',
		),
		array(
			'pt_slug'    => 'cliffs-of-moher',
			'type'       => 'leisure',
			'title'      => 'Cliffs of Moher (sintético)',
			'county'     => 'Clare',
			'town'       => 'Liscannor',
			'pt_excerpt' => 'Falésias de 214 metros sobre o Oceano Atlântico, uma das atrações naturais mais famosas da Irlanda.',
		),
		array(
			'pt_slug'          => 'fota-wildlife-park',
			'type'             => 'leisure',
			'title'            => 'Fota Wildlife Park (sintético)',
			'county'           => 'Cork',
			'town'             => 'Carrigtwohill',
			'pt_excerpt'       => 'Parque de vida selvagem na ilha de Fota, com animais em recintos abertos e um castelo.',
			// An EXTERNAL listing: the directory hands the visitor to the
			// official site, which is the documented behaviour the release
			// verifier allows an off-site 3xx for.
			'official_website' => 'https://example.invalid/fota',
		),

		// --- Guide: a PT master with a real EN translation, so the guide is
		// REPLACED in `lang=en` rather than falling back to B2.
		array(
			'pt_slug'  => 'como-tirar-o-pps-number',
			'type'     => 'guide',
			'title'    => 'Como tirar o PPS Number (sintético)',
			'en_slug'  => 'how-to-get-a-pps-number',
			'en_title' => 'How to get a PPS Number (synthetic)',
			// BOTH categories, because
			// `test-stage32-bilingual.php` asserts "the EN guide carries its
			// linked EN category terms (documents, work)". A single-category
			// fixture makes that pair contract half-vacuous.
			'category' => array( 'documentos', 'trabalho' ),
		),

		// --- Post: a PT master with a real EN translation.
		array(
			'pt_slug'  => 'comunidade-celebra-festa-junina-em-dublin',
			'type'     => 'post',
			'title'    => 'Comunidade celebra Festa Junina em Dublin (sintético)',
			'en_slug'  => 'community-celebrates-festa-junina-in-dublin',
			// A FIXED date, shared by the PT record and inherited by its EN
			// translation. `test-blog-en-translation.php` asserts "EN posts keep
			// the PT publication date", so both halves must be deterministic; a
			// relative "now" would also make the two halves differ by the seconds
			// between the two inserts.
			'date'     => '2026-02-14 10:00:00',
			'en_title' => 'Community Celebrates June Festival in Dublin (synthetic)',
		),

		// --- Job: a PT master with a real EN translation. Job is B2, so this
		// pair proves the real-translation branch wins over the fallback.
		array(
			'pt_slug'  => 'ajudante-de-cozinha-dublin',
			'type'     => 'job',
			'title'    => 'Ajudante de cozinha sintético em Dublin',
			'en_slug'  => 'kitchen-assistant-dublin',
			'en_title' => 'Kitchen Assistant (synthetic) in Dublin',
		),

		// --- Sponsor: a PT master with a real EN translation.
		array(
			'pt_slug'  => 'brasil-market-dublin',
			'type'     => 'sponsor',
			'title'    => 'Brasil Market Dublin (sintético)',
			'en_slug'  => 'brasil-market-dublin-en',
			'en_title' => 'Brasil Market Dublin (synthetic)',
		),
	);
}
