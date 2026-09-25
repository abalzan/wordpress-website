<?php
// NARIC/QQI EN body.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_en_naric(): string {
$c = '';
$c .= g_intro(
	'See how to use NARIC Ireland/QQI to compare a Brazilian qualification with the National Framework of Qualifications (NFQ) and what the limits of that comparison are.',
	'/guias/reconhecer-diploma-brasileiro-na-irlanda-naric-qqi/',
	'People who arrive with a Brazilian degree, postgraduate degree or other qualification often need to explain the level of their diploma to employers or institutions. NARIC Ireland, operated by the QQI, provides academic guidance through comparability statements.'
);
$c .= g_links( array( 'QQI guidance on recognising foreign qualifications' => 'https://www.qqi.ie/recognition-of-foreign-qualifications' ) );
$c .= g_audience( 'Brazilians with diplomas or academic qualifications obtained in Brazil who need to present their education in Ireland.' );
$c .= g_steps( array(
	'Go to the NARIC Ireland Foreign Qualifications Database.',
	'Select Brazil and look for the type and title of the qualification.',
	'If it is in the database, download the Comparability Statement.',
	'Read the comparable level in the Irish NFQ and the notes in the statement.',
	'If it is not in the database, use the QQI Qualifications Recognition Advice service.',
	'Present the statement to the employer or institution when it is requested.',
	'For a regulated profession, also check the professional body: the NARIC statement is not a professional licence.'
) );
$c .= g_documents( array(
	'Official name of the qualification.',
	'The institution that awarded the diploma.',
	'Academic documents and translations, when required by the receiving body.'
) );
$c .= g_costs( 'The NARIC database is provided free of charge by the QQI. Translations, certifications and professional processes may have their own costs.' );
$c .= g_timelines( 'There is no universal deadline: apply in advance if the statement is needed for an application.' );
$c .= g_pitfalls( array(
	'Treating a comparability statement as a professional licence.',
	'Assuming every diploma will automatically be in the database.',
	'Using an old statement without checking the current version.',
	'Ignoring the regulator for a regulated profession.'
) );
$links = array(
	'QQI - recognition of foreign qualifications' => 'https://www.qqi.ie/recognition-of-foreign-qualifications',
	'NARIC - Foreign Qualifications Database' => 'https://qsearch.qqi.ie/WebPart/Search?searchtype=recognitions',
	'QQI QHelp - Recognition Advice' => 'https://qhelp.qqi.ie/learners/qualifications-recognition-advice/',
);
$c .= g_plain_links( 'Official sources', $links );
$c .= g_plain_links( 'Useful links', $links );
$c .= g_faq( array(
	'Does NARIC recognise my diploma for any job?' => 'No. The QQI explains that the information is academic guidance and does not in itself give access to a job, a regulated profession or a course.',
	'What if my diploma is not in the database?' => 'You can request general guidance from the QQI.',
	'Do I need to translate my diploma?' => 'It depends on the institution, employer or body that requests the document.',
	'Is a comparability statement mandatory?' => 'Not necessarily: it is an official tool that may be requested or useful depending on the process.'
) );
$c .= g_links( array(
	'Check the NARIC/QQI comparability list' => 'https://qsearch.qqi.ie/WebPart/Search?searchtype=recognitions',
	'QQI/NARIC - recognition of qualifications' => 'https://www.qqi.ie/recognition-of-foreign-qualifications'
) );
$c .= g_check( '18 August 2026', '' );
$c .= g_disclaimer( 'For information only; it does not replace academic, professional or immigration advice.' );
$c .= g_sep();
	return $c;
}
