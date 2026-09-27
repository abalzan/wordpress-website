<?php
/**
 * Authored English course-provider card descriptions (versioned dataset v1).
 *
 * The English layer for the Cursos archive is a DESCRIPTION-LEVEL translation on
 * the SAME Portuguese records: one authored English description per published
 * `course_provider` record, stored in `_provider_excerpt_en` post meta by the
 * shared `conexao-translation-rollout` engine (stage
 * `en-course-provider-description`).
 *
 * This mirrors the proven Stage 7 Leisure pattern
 * (`includes/leisure-description-data.php` + `en-leisure-description`) for a
 * DIFFERENT post type. `course_provider` is a documented B2 type
 * (`conexao_b2_post_types()`), so no EN `course_provider` post is created and no
 * second identity exists — the English is a field on the PT record, exactly as
 * `_leisure_excerpt_en` is.
 *
 * Keys and fields:
 *   - array key / `slug`  : the PT post_name — the ONLY portable identity
 *                          (engineering standard §0.4). A local post ID is never
 *                          portable identity and is deliberately not carried.
 *   - `pt_source`          : the Portuguese description the English was authored
 *                          against. It is the PT-drift guard's reference: a row
 *                          whose live `post_excerpt` no longer matches (after
 *                          normalisation) is REFUSED, never silently applied.
 *   - `pt_title`           : the title captured with the source, used to verify
 *                          that the slug still resolves to the same record.
 *   - `en_description`     : the authored English card description.
 *
 * The English is a FAITHFUL translation of `pt_source`. No course category,
 * qualification, funding route, eligibility rule, schedule, location or provider
 * is introduced that the Portuguese source does not state. Proper nouns
 * (FETCH, SOLAS, Qualifax, Springboard+, Apprenticeship.ie, PLC, traineeship)
 * are preserved as the source uses them, because they are the providers' own
 * names and offerings rather than claims. The card renders the text through the
 * existing 20-word `wp_trim_words()` + `esc_html()` pipeline, identical in both
 * languages.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The authored English course-provider descriptions, keyed by PT slug.
 *
 * @return array<string,array<string,string>>
 */
function conexao_en_translation_course_provider_description_data_v1(): array {
	return array(
		'fetch-courses' => array(
			'pt_source'      => 'Encontre cursos de formação e educação continuada em toda a Irlanda, incluindo aprendizagens, traineeships, cursos PLC, educação de adultos e outras oportunidades de FET.',
			'pt_title'       => 'FETCH Courses',
			'en_description' => 'Find training and continuing education courses throughout Ireland, including apprenticeships, traineeships, PLC courses, adult education and other FET opportunities.',
		),
		'qualifax' => array(
			'pt_source'      => 'A base de dados nacional da Irlanda para cursos e orientação ao estudante, ajudando estudantes, candidatos a emprego e adultos a explorar opções de educação e formação.',
			'pt_title'       => 'Qualifax',
			'en_description' => 'Ireland\'s national database for courses and student guidance, helping students, jobseekers and adults explore education and training options.',
		),
		'springboard-plus' => array(
			'pt_source'      => 'Descubra cursos superiores subsidiados criados para ajudar as pessoas a requalificar, aprimorar competências e desenvolver novas oportunidades de carreira.',
			'pt_title'       => 'Springboard+',
			'en_description' => 'Discover subsidised further education courses designed to help people requalify, build skills and develop new career opportunities.',
		),
		'skillnet-ireland' => array(
			'pt_source'      => 'Descubra oportunidades de formação e requalificação desenhadas em torno das necessidades de empresas, colaboradores e setores da indústria em toda a Irlanda.',
			'pt_title'       => 'Skillnet Ireland',
			'en_description' => 'Discover training and reskilling opportunities designed around the needs of companies, employees and industry sectors throughout Ireland.',
		),
		'solas-ecollege' => array(
			'pt_source'      => 'Aprendizagem online e desenvolvimento de competências da SOLAS, incluindo competências digitais, TI, gestão de projetos, negócios e outras áreas.',
			'pt_title'       => 'SOLAS eCollege',
			'en_description' => 'SOLAS online learning and skills development, including digital skills, IT, project management, business and other areas.',
		),
		'local-enterprise-office' => array(
			'pt_source'      => 'Programas de formação e cursos de negócios para quem está a iniciar, desenvolver ou fazer crescer um negócio na Irlanda.',
			'pt_title'       => 'Local Enterprise Office',
			'en_description' => 'Training programmes and business courses for those starting, developing or growing a business in Ireland.',
		),

		'courses-ie' => array(
			'pt_source'      => 'Explore milhares de cursos de faculdades, universidades, fornecedores de formação e educadores independentes em toda a Irlanda.',
			'pt_title'       => 'Courses.ie',
			'en_description' => 'Explore thousands of courses from colleges, universities, training providers and independent educators throughout Ireland.',
		),
		'etbi' => array(
			'pt_source'      => 'Encontre oportunidades de educação e formação oferecidas através da rede de Education and Training Boards na Irlanda.',
			'pt_title'       => 'Education and Training Boards Ireland',
			'en_description' => 'Find education and training opportunities offered through the network of Education and Training Boards in Ireland.',
		),
		'apprenticeship-ie' => array(
			'pt_source'      => 'Portal nacional para encontrar apprenticeships na Irlanda, combinando formação prática no trabalho com formação fora do trabalho e oportunidades em diversas áreas profissionais.',
			'pt_title'       => 'Apprenticeship.ie',
			'en_description' => 'National portal to find apprenticeships in Ireland, combining on-the-job training with off-the-job training and opportunities across many occupational areas.',
		),
		'microcreds' => array(
			'pt_source'      => 'Explore micro-credenciais curtas, flexíveis e acreditadas por universidades irlandesas, com opções em áreas como negócios, tecnologia, educação, saúde e outras áreas profissionais.',
			'pt_title'       => 'MicroCreds',
			'en_description' => 'Explore short, flexible micro-credentials awarded by Irish universities, with options in areas such as business, technology, education, health and other professional fields.',
		),
	);
}
