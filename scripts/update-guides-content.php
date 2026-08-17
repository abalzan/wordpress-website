<?php
/**
 * Update all guide content with verified, official-source-based content.
 *
 * Run with: wp eval-file scripts/update-guides-content.php
 * or: docker exec wordpress-website-wordpress-1 wp eval-file /var/www/html/wp-content/../scripts/update-guides-content.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow running via WP-CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		// OK - running via WP-CLI.
	} else {
		exit( 'This script must be run via WP-CLI.' );
	}
}

/**
 * Helper: Update a guide post with content, meta description, and categories.
 */
function conexao_update_guide( $post_id, $title, $content, $meta_description, $categories = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || 'guide' !== $post->post_type ) {
		WP_CLI::log( "SKIP: Post {$post_id} not found or not a guide." );
		return;
	}

	// Update post content.
	wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $content,
		'post_title'   => $title,
	) );

	// Update meta description.
	update_post_meta( $post_id, 'conexao_meta_description', $meta_description );

	// Assign categories.
	if ( ! empty( $categories ) ) {
		wp_set_object_terms( $post_id, $categories, 'conexao_category' );
	}

	WP_CLI::log( "UPDATED: {$title} (ID: {$post_id})" );
}

/**
 * Helper: Create a new guide post.
 */
function conexao_create_guide( $title, $slug, $content, $meta_description, $categories = array() ) {
	// Check if a guide with this slug already exists.
	$existing = get_page_by_path( $slug, OBJECT, 'guide' );
	if ( $existing ) {
		WP_CLI::log( "EXISTS: {$title} (ID: {$existing->ID}) - updating instead." );
		conexao_update_guide( $existing->ID, $title, $content, $meta_description, $categories );
		return $existing->ID;
	}

	$post_id = wp_insert_post( array(
		'post_type'    => 'guide',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'post_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 30, '...' ),
	) );

	if ( $post_id && ! is_wp_error( $post_id ) ) {
		update_post_meta( $post_id, 'conexao_meta_description', $meta_description );
		if ( ! empty( $categories ) ) {
			wp_set_object_terms( $post_id, $categories, 'conexao_category' );
		}
		WP_CLI::log( "CREATED: {$title} (ID: {$post_id})" );
		return $post_id;
	}

	WP_CLI::log( "ERROR creating: {$title}" );
	return 0;
}

/**
 * Helper: Build the standard guide footer with official sources.
 */
function conexao_guide_footer( $sources, $last_verified = '17 de agosto de 2026' ) {
	$html = "\n\n<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->\n";
	$html .= "<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->\n";
	$html .= "<!-- wp:paragraph --><p><strong>Última verificação: {$last_verified}</strong></p><!-- /wp:paragraph -->\n";
	$html .= "<!-- wp:list --><ul>\n";
	foreach ( $sources as $label => $url ) {
		$html .= "<li><a href=\"{$url}\" target=\"_blank\" rel=\"noopener noreferrer\">{$label}</a></li>\n";
	}
	$html .= "</ul><!-- /wp:list -->\n";
	return $html;
}

/**
 * Helper: Build FAQ section.
 */
function conexao_guide_faq( $faqs ) {
	$html = "\n<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->\n";
	foreach ( $faqs as $q => $a ) {
		$html .= "<!-- wp:heading --><h3>{$q}</h3><!-- /wp:heading -->\n";
		$html .= "<!-- wp:paragraph --><p>{$a}</p><!-- /wp:paragraph -->\n";
	}
	return $html;
}

// ============================================================================
// 1. PPS NUMBER (ID: 46)
// ============================================================================
$pps_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o PPS Number?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>PPS Number (Personal Public Service Number)</strong> é o número de identificação pessoal mais importante na Irlanda. É um número único composto por 7 dígitos seguidos de 1 ou 2 letras (exemplo: 1234567A). Ele é usado para acessar serviços públicos, trabalhar, pagar impostos, receber benefícios sociais e acessar o sistema de saúde.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa do PPS Number?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você precisa de um PPS Number se vai:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li>Trabalhar na Irlanda (empregado ou autônomo)</li>
<li>Pagar impostos (Income Tax, USC, PRSI)</li>
<li>Acessar serviços de saúde (Medical Card, GP Visit Card)</li>
<li>Solicitar benefícios sociais (Child Benefit, Jobseeker's, etc.)</li>
<li>Abrir conta bancária (alguns bancos exigem)</li>
<li>Comprar ou alugar imóvel (em alguns casos)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que você precisa para solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para solicitar o PPS Number, você precisa de:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><strong>Documento de identidade</strong>: Passaporte válido (brasileiro ou de outro país)</li>
<li><strong>Comprovante de endereço na Irlanda</strong>: Conta de luz, água, internet, contrato de aluguel ou carta de um órgão público</li>
<li><strong>Comprovante de que você precisa do PPS</strong>: Carta de oferta de emprego, carta do Department of Social Protection, ou outro documento que justifique a necessidade</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O processo é feito pelo <strong>Department of Social Protection (DSP)</strong>:</p><!-- /wp:paragraph -->
<!-- wp:list --><ol>
<li>Preencha o formulário online no site do DSP (formulário PPS1)</li>
<li>Envie o formulário e os documentos por correio ou entregue pessoalmente no seu <strong>Intreo Centre</strong> local</li>
<li>Você pode ser chamado para uma entrevista presencial</li>
<li>O PPS Number é enviado pelo correio para o seu endereço na Irlanda</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O PPS Number é <strong>gratuito</strong>. Não há taxa para solicitar.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quanto tempo leva?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O processamento pode levar de <strong>alguns dias a algumas semanas</strong>, dependendo do volume de solicitações e se você precisa de entrevista. O DSP não publica um prazo fixo garantido.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar PPS Number:</strong> <a href="https://www.gov.ie/en/department-of-social-protection/services/get-a-personal-public-service-pps-number/" target="_blank" rel="noopener noreferrer">gov.ie - Get a PPS Number</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O PPS Number é <strong>pessoal e intransferível</strong>. Nunca compartilhe com terceiros.</li>
<li>Você só pode solicitar se <strong>precisar</strong> do número para um serviço específico. Não é possível solicitar "por precaução".</li>
<li>Se você já teve um PPS Number antes, não precisa solicitar outro. Use o mesmo número.</li>
<li>Guarde o cartão ou carta com seu PPS Number em local seguro.</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Posso trabalhar sem PPS Number?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você pode começar a trabalhar enquanto aguarda o PPS Number, mas seu empregador precisa do número para registrá-lo no Revenue e pagar seus impostos corretamente. O empregador pode usar um número temporário, mas você deve informar seu PPS Number assim que recebê-lo.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Preciso de visto para solicitar PPS Number?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você precisa estar legalmente na Irlanda. Brasileiros não precisam de visto para entrar na Irlanda como turistas (até 90 dias), mas para trabalhar e solicitar PPS Number, você precisa de uma permissão de trabalho válida (Employment Permit) ou outro status migratório que permita trabalhar.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O PPS Number expira?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não. O PPS Number é permanente e não expira, mesmo se você sair da Irlanda e voltar depois.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.gov.ie/en/department-of-social-protection/services/get-a-personal-public-service-pps-number/" target="_blank" rel="noopener noreferrer">gov.ie - Get a PPS Number</a></li>
<li><a href="https://www.citizensinformation.ie/en/social-welfare/irish-social-welfare-system/personal-public-service-number/" target="_blank" rel="noopener noreferrer">Citizens Information - PPS Number</a></li>
<li><a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">MyWelfare - Portal de serviços sociais</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	46,
	'Como conseguir um PPS Number na Irlanda',
	$pps_content,
	'Guia completo sobre como conseguir seu PPS Number na Irlanda. Documentos necessários, processo passo a passo, custos e prazos. Informação verificada em fontes oficiais.',
	array( 'documentos', 'financas' )
);

// ============================================================================
// 2. MEDICAL CARD (ID: 47)
// ============================================================================
$medical_card_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Medical Card?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Medical Card</strong> é um cartão do sistema de saúde irlandês (HSE) que dá acesso <strong>gratuito</strong> a uma ampla gama de serviços de saúde. Ele é baseado em um teste de renda (means test) e é destinado a pessoas com renda abaixo de certos limites.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem tem direito?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para ter direito ao Medical Card, você deve ser <strong>residente habitual</strong> na Irlanda (living here and intending to live here for at least one year) e passar no teste de renda. O teste considera a renda <strong>líquida</strong> semanal da família (após impostos, PRSI e USC).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Limites de renda (semanal, renda líquida) - Menores de 70 anos</h2><!-- /wp:heading -->
<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Categoria</th><th>Medical Card (até 66 anos)</th><th>Medical Card (66-70)</th><th>GP Visit Card</th></tr></thead><tbody>
<tr><td>Pessoa solteira vivendo sozinha</td><td>€184</td><td>€201,50</td><td>€418</td></tr>
<tr><td>Pessoa solteira vivendo com família</td><td>€164</td><td>€173,50</td><td>€373</td></tr>
<tr><td>Casal (ou pai/mãe solteiro com filhos)</td><td>€266,50</td><td>€298</td><td>€607</td></tr>
<tr><td>Acréscimo por filho menor de 16 (1º e 2º)</td><td>€38</td><td>€38</td><td>€57</td></tr>
<tr><td>Acréscimo por filho menor de 16 (3º+)</td><td>€41</td><td>€41</td><td>€61,50</td></tr>
</tbody></table></figure><!-- /wp:table -->

<!-- wp:heading --><h2>O que o Medical Card cobre?</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Consultas médicas <strong>gratuitas</strong> com GP (médico de família)</li>
<li>Medicamentos prescritos com taxa reduzida (€2 por item, com teto mensal de €80 por família)</li>
<li>Atendimento hospitalar público <strong>gratuito</strong> (incluindo cirurgias e internações)</li>
<li>Exames e tratamentos em hospitais públicos</li>
<li>Serviços de maternidade</li>
<li>Atendimento odontológico, oftalmológico e auditivo (em alguns casos)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Baixe o formulário <strong>MC1</strong> no site do HSE ou solicite pelo correio</li>
<li>Preencha com seus dados e os da sua família</li>
<li>Envie o formulário com os documentos comprobatórios (identidade, comprovante de residência, comprovantes de renda)</li>
<li>O HSE analisa sua solicitação e envia a decisão pelo correio</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O Medical Card é <strong>gratuito</strong>. Não há taxa de solicitação.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar Medical Card:</strong> <a href="https://www2.hse.ie/services/medical-cards/" target="_blank" rel="noopener noreferrer">HSE - Medical Cards</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O Medical Card é <strong>gratuito</strong> para quem passa no teste de renda</li>
<li>Se você não passa no teste para o Medical Card, pode ter direito ao <strong>GP Visit Card</strong>, que cobre consultas de GP gratuitas (mas não medicamentos ou hospital)</li>
<li>O cartão precisa ser <strong>renovado</strong> periodicamente</li>
<li>Pessoas com condições médicas específicas podem ter direito ao Medical Card mesmo com renda acima do limite (através do <em>discretionary medical card</em>)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem ter Medical Card?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, se você é residente habitual na Irlanda (morando aqui e com intenção de ficar por pelo menos 1 ano) e passa no teste de renda. Ter um Employment Permit ou outro status migratório válido ajuda a comprovar residência.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o GP Visit Card?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O GP Visit Card cobre consultas gratuitas com o médico de família (GP), mas não cobre medicamentos, exames ou hospital. Os limites de renda são mais altos que os do Medical Card.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Preciso de PPS Number para solicitar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, você precisa de um PPS Number para solicitar o Medical Card.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www2.hse.ie/services/medical-cards/" target="_blank" rel="noopener noreferrer">HSE - Medical Cards</a></li>
<li><a href="https://www.citizensinformation.ie/en/health/medical-cards-and-gp-visit-cards/medical-card/" target="_blank" rel="noopener noreferrer">Citizens Information - Medical Card</a></li>
<li><a href="https://www.citizensinformation.ie/en/health/medical-cards-and-gp-visit-cards/medical-card-means-test-under-70s/" target="_blank" rel="noopener noreferrer">Citizens Information - Medical Card Means Test</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	47,
	'Medical Card na Irlanda: Guia Completo',
	$medical_card_content,
	'Guia completo sobre o Medical Card na Irlanda. Quem tem direito, limites de renda, benefícios, como solicitar e informações verificadas em fontes oficiais.',
	array( 'saude' )
);

// ============================================================================
// 3. GP REGISTRATION (ID: 48)
// ============================================================================
$gp_content = <<<'HTML'
<!-- wp:heading --><h2>O que é um GP?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>GP (General Practitioner)</strong> é o médico de família na Irlanda. É o primeiro ponto de contato para qualquer questão de saúde. Na Irlanda, você não vai direto ao hospital - primeiro consulta o GP, que encaminha para especialistas se necessário.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa se registrar em um GP?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os residentes na Irlanda devem ter um GP. É essencial para:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Consultas médicas de rotina</li>
<li>Receitas de medicamentos</li>
<li>Encaminhamento para especialistas</li>
<li>Atestados médicos</li>
<li>Vacinas</li>
<li>Exames preventivos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como encontrar um GP</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Use o <strong>HSE Find a GP</strong> para buscar clínicas na sua área</li>
<li>Pergunte a amigos, colegas de trabalho ou vizinhos</li>
<li>Verifique se a clínica está <strong>aceitando novos pacientes</strong> (algumas têm lista de espera)</li>
<li>Ligue para a clínica e pergunte sobre o processo de registro</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Como se registrar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Escolha uma clínica (GP practice) perto de você</li>
<li>Ligue ou visite a clínica para verificar se estão aceitando novos pacientes</li>
<li>Preencha o formulário de registro da clínica</li>
<li>Leve seu <strong>PPS Number</strong> e documento de identidade</li>
<li>Se tiver Medical Card ou GP Visit Card, informe a clínica</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O custo de uma consulta de GP varia de clínica para clínica (geralmente entre <strong>€50 e €70</strong> por consulta). Se você tem <strong>Medical Card</strong> ou <strong>GP Visit Card</strong>, a consulta é <strong>gratuita</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde encontrar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Encontrar um GP:</strong> <a href="https://www2.hse.ie/services/find-a-gp/" target="_blank" rel="noopener noreferrer">HSE - Find a GP</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Você pode se registrar em qualquer GP que esteja aceitando pacientes - não precisa ser na sua área, mas é mais prático</li>
<li>Se você tem Medical Card, o GP é <strong>gratuito</strong></li>
<li>Em emergências, ligue <strong>112</strong> ou <strong>999</strong></li>
<li>Fora do horário de funcionamento, muitas clínicas têm um serviço de plantão (out-of-hours service)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de PPS Number para me registrar em um GP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, o PPS Number é necessário para se registrar em um GP na Irlanda, especialmente se você quer usar o Medical Card ou GP Visit Card.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Posso ir direto ao hospital sem GP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Em emergências, sim - ligue 112/999 ou vá ao pronto-socorro (Emergency Department). Para questões não urgentes, você deve primeiro consultar um GP.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www2.hse.ie/services/find-a-gp/" target="_blank" rel="noopener noreferrer">HSE - Find a GP</a></li>
<li><a href="https://www.citizensinformation.ie/en/health/health-services/gp-and-hospital-services/gp-services/" target="_blank" rel="noopener noreferrer">Citizens Information - GP Services</a></li>
<li><a href="https://www2.hse.ie/services/medical-cards/" target="_blank" rel="noopener noreferrer">HSE - Medical Cards</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	48,
	'Como se registrar em um GP (Médico de Família) na Irlanda',
	$gp_content,
	'Guia para se registrar em um GP (médico de família) na Irlanda. Como encontrar, quanto custa, documentos necessários e informações verificadas em fontes oficiais.',
	array( 'saude' )
);

// ============================================================================
// 4. BANK ACCOUNT (ID: 49)
// ============================================================================
$bank_content = <<<'HTML'
<!-- wp:heading --><h2>O que é uma conta bancária na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ter uma conta bancária irlandesa é essencial para receber salário, pagar contas, receber benefícios sociais e administrar suas finanças. A maioria dos empregadores paga salário por transferência bancária.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa de uma conta bancária?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Praticamente todos os residentes na Irlanda. É necessária para:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Receber salário</li>
<li>Pagar contas (aluguel, luz, internet, etc.)</li>
<li>Receber benefícios sociais (Child Benefit, etc.)</li>
<li>Receber reembolsos de impostos do Revenue</li>
<li>Fazer compras online e pagamentos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que você precisa para abrir uma conta</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Documento de identidade</strong>: Passaporte válido</li>
<li><strong>Comprovante de endereço na Irlanda</strong>: Conta de luz, água, internet, contrato de aluguel, ou carta de um órgão público</li>
<li><strong>PPS Number</strong>: A maioria dos bancos exige</li>
<li><strong>Comprovante de renda</strong> (em alguns casos): Contrato de trabalho, últimas folhas de pagamento</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como abrir uma conta</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Escolha um banco (AIB, Bank of Ireland, Permanent TSB, etc.)</li>
<li>Agende uma visita à agência ou inicie o processo online</li>
<li>Leve seus documentos (identidade, comprovante de endereço, PPS Number)</li>
<li>Preencha o formulário de abertura de conta</li>
<li>O banco analisa sua solicitação e ativa a conta</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A abertura de conta é <strong>gratuita</strong> na maioria dos bancos. Alguns bancos cobram taxas mensais de manutenção (geralmente entre €4 e €6 por mês). Existem também <strong>contas básicas gratuitas</strong> (Basic Bank Account) para pessoas de baixa renda.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde abrir</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os principais bancos na Irlanda incluem: <strong>AIB</strong>, <strong>Bank of Ireland</strong>, <strong>Permanent TSB</strong>, <strong>Revolut</strong> (banco digital). O <strong>Central Bank of Ireland</strong> regula os bancos no país.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Alguns bancos podem exigir que você já tenha um emprego ou renda na Irlanda</li>
<li>O <strong>IBAN</strong> irlandês começa com "IE"</li>
<li>Você pode precisar do PPS Number para abrir conta - se ainda não tem, alguns bancos permitem abrir com passaporte e comprovante de endereço</li>
<li>Bancos digitais como Revolut e N26 podem ser mais fáceis para recém-chegados</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de PPS Number para abrir conta?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A maioria dos bancos exige PPS Number, mas alguns podem permitir abrir conta com passaporte e comprovante de endereço. Verifique com o banco escolhido.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Posso abrir conta sem emprego?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, é possível, mas alguns bancos podem pedir comprovante de renda ou exigir um depósito inicial.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.citizensinformation.ie/en/consumer/financial-services/bank-accounts/" target="_blank" rel="noopener noreferrer">Citizens Information - Bank Accounts</a></li>
<li><a href="https://www.ccpc.ie/consumers/money/bank-accounts/" target="_blank" rel="noopener noreferrer">CCPC - Bank Accounts</a></li>
<li><a href="https://www.centralbank.ie/" target="_blank" rel="noopener noreferrer">Central Bank of Ireland</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	49,
	'Abrir uma Conta Bancária na Irlanda',
	$bank_content,
	'Guia passo a passo para abrir uma conta bancária na Irlanda. Documentos necessários, bancos, custos e informações verificadas em fontes oficiais.',
	array( 'financas' )
);

// ============================================================================
// 5. RENTING A HOUSE (ID: 50)
// ============================================================================
$rent_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o mercado de aluguel na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O mercado imobiliário irlandês é <strong>competitivo</strong>, especialmente em Dublin e outras cidades grandes. A demanda é alta e os preços são elevados. É importante entender as regras e seus direitos como inquilino.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa alugar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Praticamente todos os recém-chegados à Irlanda começam alugando. O mercado de compra de imóveis é caro e exige histórico de crédito.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que você precisa para alugar</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Documento de identidade</strong>: Passaporte válido</li>
<li><strong>Comprovante de renda</strong>: Contrato de trabalho, últimas folhas de pagamento (geralmente 3 meses)</li>
<li><strong>Referências</strong>: De empregador e/ou de locadores anteriores</li>
<li><strong>PPS Number</strong>: Em alguns casos</li>
<li><strong>Depósito</strong>: Geralmente 1 mês de aluguel (máximo legal de 2 meses)</li>
<li><strong>Primeiro mês de aluguel adiantado</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Onde procurar</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Daft.ie</strong> - O maior portal de imóveis da Irlanda</li>
<li><strong>Rent.ie</strong> - Portal de aluguel</li>
<li><strong>MyHome.ie</strong> - Portal de imóveis</li>
<li>Agências imobiliárias locais</li>
<li>Grupos de Facebook de brasileiros na Irlanda (com cautela)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como alugar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Pesquise em portais de imóveis e agências</li>
<li>Entre em contato rapidamente - imóveis bons são alugados em dias</li>
<li>Agende uma visita ao imóvel</li>
<li>Prepare seus documentos com antecedência</li>
<li>Se aprovado, assine o <strong>contrato de arrendamento</strong> (tenancy agreement)</li>
<li>Pague o depósito e o primeiro mês</li>
<li>O locador deve registrar o contrato no <strong>RTB (Residential Tenancies Board)</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os preços variam muito por localização. Em Dublin, um quarto em casa compartilhada custa em média <strong>€800-€1.200/mês</strong>. Um apartamento de 1 quarto custa <strong>€1.500-€2.200/mês</strong>. Fora de Dublin, os preços são mais baixos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Seus direitos como inquilino</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O <strong>RTB (Residential Tenancies Board)</strong> é o órgão que regula o aluguel na Irlanda</li>
<li>O depósito máximo é de <strong>2 meses de aluguel</strong></li>
<li>O locador deve fornecer um <strong>recibo</strong> do depósito</li>
<li>O contrato deve ser <strong>registrado no RTB</strong> dentro de 1 mês</li>
<li>O locador deve dar <strong>aviso prévio</strong> para terminar o contrato (varia de 90 a 152 dias dependendo do tempo de residência)</li>
<li>Em <strong>Rent Pressure Zones</strong> (áreas com alta demanda), o aumento de aluguel é limitado</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Desconfie de golpes - nunca pague depósito antes de visitar o imóvel</li>
<li>Verifique se o imóvel tem <strong>Eircode</strong> (código postal)</li>
<li>Leia o contrato com atenção antes de assinar</li>
<li>Guarde todos os recibos de pagamento</li>
<li>Se tiver problemas com o locador, contate o <strong>RTB</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de PPS Number para alugar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Nem sempre, mas muitos locadores pedem. Ter PPS Number facilita o processo.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o RTB?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O RTB (Residential Tenancies Board) é o órgão oficial que regula o aluguel residencial na Irlanda. Ele registra contratos, resolve disputas e fornece informações sobre direitos de inquilinos e locadores.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto depósito posso pagar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O depósito máximo legal é de <strong>2 meses de aluguel</strong>. O locador não pode pedir mais que isso.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.rtb.ie/" target="_blank" rel="noopener noreferrer">RTB - Residential Tenancies Board</a></li>
<li><a href="https://www.citizensinformation.ie/en/housing/renting-a-home/" target="_blank" rel="noopener noreferrer">Citizens Information - Renting a Home</a></li>
<li><a href="https://www.gov.ie/en/department-of-housing-local-government-and-heritage/" target="_blank" rel="noopener noreferrer">Department of Housing</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	50,
	'Alugar uma Casa na Irlanda: Guia Completo',
	$rent_content,
	'Guia completo para alugar casa na Irlanda. Onde procurar, documentos necessários, direitos do inquilino, custos e informações verificadas em fontes oficiais.',
	array( 'moradia' )
);

// ============================================================================
// 6. BUYING A CAR (ID: 51)
// ============================================================================
$car_content = <<<'HTML'
<!-- wp:heading --><h2>O que é preciso para comprar um carro na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Comprar um carro na Irlanda envolve vários passos: escolher o veículo, verificar o histórico, transferir a propriedade, pagar o motor tax e fazer o seguro. Este guia explica o processo.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode comprar um carro?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Qualquer pessoa com 18 anos ou mais e com carteira de motorista válida pode comprar um carro na Irlanda. Você não precisa ser cidadão irlandês.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que você precisa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Carteira de motorista</strong> válida (irlandesa ou estrangeira)</li>
<li><strong>Documento de identidade</strong></li>
<li><strong>Comprovante de endereço</strong></li>
<li><strong>Seguro de carro</strong> (obrigatório na Irlanda)</li>
<li><strong>Motor Tax</strong> (imposto anual do veículo)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como comprar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Defina seu orçamento (incluindo seguro, taxas e manutenção)</li>
<li>Pesquise em sites como <strong>DoneDeal</strong> e <strong>CarsIreland</strong>, ou em concessionárias</li>
<li>Verifique o histórico do veículo (quilometragem, acidentes, etc.)</li>
<li>Faça um test drive</li>
<li>Verifique se o carro tem <strong>NCT</strong> válido (inspeção técnica)</li>
<li>Complete a transferência de propriedade</li>
<li>Pague o <strong>Motor Tax</strong> e faça o <strong>seguro</strong></li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>O que é o NCT?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>NCT (National Car Test)</strong> é a inspeção técnica obrigatória para carros com mais de 4 anos. O carro deve passar no NCT para poder circular legalmente. A inspeção verifica freios, pneus, luzes, emissões, etc.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que é o Motor Tax?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Motor Tax</strong> é o imposto anual de circulação do veículo. O valor varia conforme o tamanho do motor e as emissões de CO2. Você pode pagar online no site <strong>motortax.ie</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O <strong>seguro de carro</strong> é obrigatório na Irlanda</li>
<li>O <strong>NCT</strong> é obrigatório para carros com mais de 4 anos</li>
<li>O <strong>Motor Tax</strong> deve ser pago anualmente</li>
<li>Verifique se o carro não tem <strong>multas pendentes</strong> ou dívidas de motor tax</li>
<li>Considere o custo do seguro - pode ser alto para recém-chegados sem histórico na Irlanda</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de carteira irlandesa para comprar carro?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não necessariamente. Você pode comprar um carro com carteira estrangeira válida, mas precisa de seguro e motor tax. Para dirigir por mais de 1 ano na Irlanda, você precisa trocar sua carteira pela irlandesa.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o Eircode?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O Eircode é o código postal irlandês (7 caracteres, ex: D01 F5P2). Todo endereço na Irlanda tem um Eircode único. Você pode encontrar o Eircode de um endereço no site eircode.ie.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.ncts.ie/" target="_blank" rel="noopener noreferrer">NCT - National Car Test</a></li>
<li><a href="https://www.motortax.ie/" target="_blank" rel="noopener noreferrer">Motor Tax Online</a></li>
<li><a href="https://www.rsa.ie/" target="_blank" rel="noopener noreferrer">RSA - Road Safety Authority</a></li>
<li><a href="https://www.citizensinformation.ie/en/travel-and-recreation/motoring/buying-or-selling-a-vehicle/" target="_blank" rel="noopener noreferrer">Citizens Information - Buying a Vehicle</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	51,
	'Comprar um Carro na Irlanda: Guia Completo',
	$car_content,
	'Guia para comprar carro na Irlanda. Documentação, NCT, motor tax, seguro e informações verificadas em fontes oficiais.',
	array( 'transporte' )
);

// ============================================================================
// 7. DRIVING LICENCE (ID: 52)
// ============================================================================
$licence_content = <<<'HTML'
<!-- wp:heading --><h2>O que é a carteira de motorista irlandesa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A carteira de motorista irlandesa é emitida pelo <strong>NDLS (National Driver Licence Service)</strong>. Brasileiros podem dirigir na Irlanda com a CNH brasileira por até <strong>12 meses</strong> após chegar ao país. Depois desse período, é necessário trocar pela carteira irlandesa.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa trocar a CNH?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Brasileiros que pretendem morar na Irlanda por mais de 12 meses precisam trocar a CNH pela carteira irlandesa. A troca é possível porque o Brasil tem um <strong>acordo de reciprocidade</strong> com a Irlanda.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que você precisa para trocar</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>CNH brasileira válida</strong> (não pode estar suspensa ou cassada)</li>
<li><strong>Passaporte</strong> válido</li>
<li><strong>Comprovante de endereço na Irlanda</strong> (com menos de 6 meses)</li>
<li><strong>PPS Number</strong></li>
<li><strong>Comprovante de residência na Irlanda</strong> (por pelo menos 185 dias no ano)</li>
<li><strong>Tradução juramentada da CNH</strong> para inglês (se necessário)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como trocar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Preencha o formulário online no site do <strong>NDLS</strong></li>
<li>Agende uma visita ao centro <strong>NDLS</strong> mais próximo</li>
<li>Leve seus documentos originais</li>
<li>Pague a taxa</li>
<li>Receba sua carteira irlandesa pelo correio (geralmente em 5-10 dias úteis)</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa para trocar a carteira é de <strong>€55</strong> (para 10 anos de validade).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar troca de carteira:</strong> <a href="https://www.ndls.ie/" target="_blank" rel="noopener noreferrer">NDLS - National Driver Licence Service</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Você pode dirigir com a CNH brasileira por até <strong>12 meses</strong> após chegar na Irlanda</li>
<li>Após 12 meses, é <strong>obrigatório</strong> ter a carteira irlandesa</li>
<li>Se sua CNH brasileira expirar, você não pode renová-la na Irlanda - precisa trocar pela irlandesa</li>
<li>O <strong>seguro de carro</strong> pode ser mais caro para quem tem CNH estrangeira</li>
<li>Se você não pode trocar a CNH (por exemplo, se ela expirou), precisará fazer o processo completo: <strong>teoria, aulas e exame prático</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Posso dirigir com CNH brasileira na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, por até 12 meses após chegar na Irlanda. Depois disso, você precisa trocar pela carteira irlandesa.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Preciso fazer exame para trocar a CNH?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não, se sua CNH é válida e o Brasil tem acordo com a Irlanda, você pode trocar sem fazer exame teórico ou prático.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto tempo leva para receber a carteira?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Geralmente de 5 a 10 dias úteis após a solicitação, mas pode variar.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.ndls.ie/" target="_blank" rel="noopener noreferrer">NDLS - National Driver Licence Service</a></li>
<li><a href="https://www.rsa.ie/" target="_blank" rel="noopener noreferrer">RSA - Road Safety Authority</a></li>
<li><a href="https://www.citizensinformation.ie/en/travel-and-recreation/motoring/driver-licensing/exchanging-foreign-driving-permit/" target="_blank" rel="noopener noreferrer">Citizens Information - Exchanging Foreign Driving Permit</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	52,
	'Carteira de Motorista na Irlanda: Como Trocar a CNH',
	$licence_content,
	'Guia para obter a carteira de motorista na Irlanda. Troca da CNH brasileira, documentos, custos e informações verificadas em fontes oficiais.',
	array( 'transporte' )
);

// ============================================================================
// 8. TAXES (ID: 53)
// ============================================================================
$tax_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o sistema de impostos na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O sistema tributário irlandês é administrado pelo <strong>Revenue (Office of the Revenue Commissioners)</strong>. Se você trabalha na Irlanda, seus impostos são descontados automaticamente do seu salário através do sistema <strong>PAYE (Pay As You Earn)</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa pagar impostos?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os trabalhadores na Irlanda pagam impostos. Se você é empregado, o imposto é descontado automaticamente. Se é autônomo (self-employed), você precisa declarar e pagar seus impostos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Principais impostos</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Income Tax (Imposto de Renda)</strong>: Imposto progressivo sobre sua renda</li>
<li><strong>USC (Universal Social Charge)</strong>: Imposto social universal</li>
<li><strong>PRSI (Pay Related Social Insurance)</strong>: Contribuição para a seguridade social</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como funciona o Income Tax</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O Income Tax é progressivo. Para 2026, as taxas são:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><strong>20%</strong> sobre a renda até o limite da faixa padrão (standard rate band)</li>
<li><strong>40%</strong> sobre a renda acima desse limite</li>
</ul><!-- /wp:list -->
<!-- wp:paragraph --><p>O limite da faixa padrão varia conforme seu status (solteiro, casado, etc.). Para uma pessoa solteira, o limite é de aproximadamente <strong>€42.000</strong> por ano.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como funciona o USC</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O USC é um imposto social que incide sobre a renda bruta. As taxas variam de <strong>0,5% a 8%</strong> dependendo da sua renda. Pessoas com renda anual abaixo de <strong>€13.000</strong> não pagam USC.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como funciona o PRSI</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O PRSI é a contribuição para a seguridade social irlandesa. A taxa é de <strong>4%</strong> para a maioria dos trabalhadores (com algumas exceções). O PRSI dá direito a benefícios como Jobseeker's Benefit, Illness Benefit e pensão.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como funciona o PAYE</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>PAYE (Pay As You Earn)</strong> é o sistema pelo qual seu empregador desconta automaticamente Income Tax, USC e PRSI do seu salário. Você não precisa fazer nada - o imposto é retido na fonte.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Créditos fiscais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você tem direito a <strong>créditos fiscais</strong> (tax credits) que reduzem o imposto que você paga. O crédito principal é o <strong>Personal Tax Credit</strong>, que para 2026 é de aproximadamente <strong>€1.875</strong> por ano para pessoas solteiras.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como registrar no Revenue</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Crie uma conta no <strong>Revenue MyAccount</strong> (usando seu PPS Number)</li>
<li>Registre seu emprego ou atividade</li>
<li>Verifique se seus créditos fiscais estão corretos</li>
<li>Se pagou imposto a mais, solicite reembolso</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Acessar o Revenue:</strong> <a href="https://www.revenue.ie/" target="_blank" rel="noopener noreferrer">Revenue - Office of the Revenue Commissioners</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Se você trabalhou em mais de um emprego, pode ter pago imposto a mais e ter direito a <strong>reembolso</strong></li>
<li>O <strong>ano fiscal</strong> na Irlanda é de janeiro a dezembro</li>
<li>Se você é autônomo, precisa declarar impostos anualmente (Form 11)</li>
<li>O Revenue pode <strong>multar</strong> quem não declara impostos corretamente</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso declarar imposto de renda na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Se você é empregado (PAYE), o imposto é descontado automaticamente. Você pode precisar declarar se tem outras fontes de renda, é autônomo, ou quer solicitar reembolso.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Como solicitar reembolso de impostos?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você pode solicitar reembolso pelo <strong>Revenue MyAccount</strong>. Se pagou imposto a mais, o Revenue devolve automaticamente ou você pode solicitar.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o PPS Number no contexto de impostos?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O PPS Number é usado como identificação fiscal na Irlanda. É o equivalente ao CPF brasileiro para fins de impostos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.revenue.ie/" target="_blank" rel="noopener noreferrer">Revenue - Office of the Revenue Commissioners</a></li>
<li><a href="https://www.citizensinformation.ie/en/money-and-tax/tax/income-tax/how-your-tax-is-calculated/" target="_blank" rel="noopener noreferrer">Citizens Information - How Your Tax is Calculated</a></li>
<li><a href="https://www.citizensinformation.ie/en/money-and-tax/tax/income-tax/universal-social-charge/" target="_blank" rel="noopener noreferrer">Citizens Information - USC</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	53,
	'Impostos na Irlanda: Guia Completo (Income Tax, USC, PRSI)',
	$tax_content,
	'Guia completo sobre impostos na Irlanda. Income Tax, USC, PRSI, PAYE, créditos fiscais e como declarar. Informação verificada em fontes oficiais.',
	array( 'financas' )
);

// ============================================================================
// 9. CITIZENSHIP (ID: 54)
// ============================================================================
$citizenship_content = <<<'HTML'
<!-- wp:heading --><h2>O que é a cidadania irlandesa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A cidadania irlandesa pode ser obtida por <strong>nascimento</strong>, <strong>descendência</strong> (Foreign Birth Registration) ou <strong>naturalização</strong>. Para a maioria dos brasileiros, o caminho é a <strong>naturalização</strong> após um período de residência legal na Irlanda.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode solicitar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você pode solicitar a cidadania por naturalização se:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Tem <strong>residência legal</strong> na Irlanda por um período contínuo</li>
<li>Tem <strong>intenção de continuar residindo</strong> na Irlanda</li>
<li>Tem <strong>bom caráter</strong> (sem condenações criminais relevantes)</li>
<li>Pretende permanecer na Irlanda após obter a cidadania</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Requisitos de residência</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os requisitos de residência para naturalização são:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><strong>1 ano de residência contínua</strong> imediatamente antes da solicitação</li>
<li>Mais <strong>3 anos de residência</strong> nos 8 anos anteriores (total de 4 anos nos últimos 8)</li>
<li>Ou <strong>3 anos de residência contínua</strong> se casado(a) com cidadão(ã) irlandês(a)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que você precisa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Passaporte</strong> válido</li>
<li><strong>IRP (Irish Residence Permit)</strong> válido</li>
<li><strong>Comprovante de residência</strong> na Irlanda</li>
<li><strong>Certidão de nascimento</strong> (traduzida se necessário)</li>
<li><strong>Comprovante de endereço</strong></li>
<li><strong>Comprovante de bom caráter</strong> (certidão de antecedentes criminais)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Preencha o formulário de naturalização (Form 8)</li>
<li>Reúna todos os documentos necessários</li>
<li>Envie a solicitação com a taxa para o <strong>Department of Justice</strong></li>
<li>Aguarde a análise (pode levar vários meses)</li>
<li>Se aprovado, participe da <strong>cerimônia de cidadania</strong></li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa de solicitação de naturalização é de <strong>€175</strong> (não reembolsável). Se aprovado, a taxa de certificado é de <strong>€950</strong> para adultos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quanto tempo leva?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O processamento pode levar de <strong>6 a 12 meses</strong> ou mais, dependendo do volume de solicitações. O Department of Justice não publica um prazo fixo garantido.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar cidadania:</strong> <a href="https://www.irishimmigration.ie/how-to-become-a-citizen/" target="_blank" rel="noopener noreferrer">Irish Immigration - How to Become a Citizen</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>A Irlanda <strong>permite dupla cidadania</strong> - você não precisa renunciar à cidadania brasileira</li>
<li>O tempo de residência é contado a partir do seu <strong>registro de imigração</strong> (IRP)</li>
<li>Períodos de residência como estudante (Stamp 2) contam parcialmente</li>
<li>Se você tem ascendência irlandesa (avós ou bisavós), pode ter direito à cidadania por <strong>Foreign Birth Registration</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso renunciar à cidadania brasileira?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não. A Irlanda permite dupla cidadania, e o Brasil também. Você pode ter as duas cidadanias.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto tempo preciso morar na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Geralmente 4 anos de residência legal nos últimos 8 anos, com pelo menos 1 ano contínuo imediatamente antes da solicitação.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é a cerimônia de cidadania?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É uma cerimônia oficial onde você faz o juramento de fidelidade à Irlanda e recebe seu certificado de naturalização. É um evento formal e obrigatório.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.irishimmigration.ie/how-to-become-a-citizen/" target="_blank" rel="noopener noreferrer">Irish Immigration - How to Become a Citizen</a></li>
<li><a href="https://www.citizensinformation.ie/en/moving-country/irish-citizenship/becoming-an-irish-citizen-through-naturalisation/" target="_blank" rel="noopener noreferrer">Citizens Information - Naturalisation</a></li>
<li><a href="https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/" target="_blank" rel="noopener noreferrer">Department of Justice</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	54,
	'Cidadania Irlandesa: Guia Completo de Naturalização',
	$citizenship_content,
	'Guia sobre cidadania irlandesa. Requisitos de residência, processo de naturalização, taxas e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// ============================================================================
// 10. PASSPORT (ID: 55)
// ============================================================================
$passport_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o passaporte irlandês?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O passaporte irlandês é emitido pelo <strong>Passport Service</strong> do <strong>Department of Foreign Affairs (DFA)</strong>. É um dos passaportes mais fortes do mundo, permitindo viagem sem visto para muitos países.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode solicitar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você pode solicitar o passaporte irlandês se é:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li><strong>Cidadão irlandês</strong> por nascimento</li>
<li><strong>Cidadão irlandês</strong> por naturalização</li>
<li><strong>Cidadão irlandês</strong> por Foreign Birth Registration</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que você precisa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Certificado de naturalização</strong> (se naturalizado)</li>
<li><strong>Certidão de nascimento</strong> (se cidadão por nascimento)</li>
<li><strong>Documento de identidade</strong> com foto</li>
<li><strong>Foto digital</strong> nos padrões exigidos</li>
<li><strong>Testemunha</strong> (witness) para assinar o formulário</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>Passport Service</strong></li>
<li>Crie uma conta ou faça login</li>
<li>Preencha o formulário online</li>
<li>Envie a foto digital</li>
<li>Pague a taxa</li>
<li>Acompanhe o status da solicitação online</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>As taxas do passaporte irlandês (solicitação online) são:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><strong>Passaporte adulto (10 anos)</strong>: €75</li>
<li><strong>Passaporte criança (5 anos)</strong>: €20</li>
<li><strong>Passaporte grande (66 páginas)</strong>: €105</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto tempo leva?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O processamento de passaportes online geralmente leva de <strong>10 a 20 dias úteis</strong> para solicitações simples. Solicitações por correio podem levar mais tempo. O Passport Service publica os prazos atuais no site.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar passaporte:</strong> <a href="https://www.ireland.ie/en/dfa/passports/" target="_blank" rel="noopener noreferrer">Passport Service - Ireland.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O passaporte irlandês é válido por <strong>10 anos</strong> para adultos</li>
<li>Você pode solicitar o passaporte <strong>imediatamente</strong> após receber o certificado de naturalização</li>
<li>O passaporte irlandês permite <strong>viagem sem visto</strong> para muitos países, incluindo o Reino Unido e a União Europeia</li>
<li>Se você tem cidadania brasileira e irlandesa, pode usar o passaporte irlandês para viajar pela Europa</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Posso ter passaporte brasileiro e irlandês?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim. A Irlanda e o Brasil permitem dupla cidadania. Você pode ter os dois passaportes.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto tempo leva para receber o passaporte?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para solicitações online simples, geralmente de 10 a 20 dias úteis. Verifique os prazos atuais no site do Passport Service.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.ireland.ie/en/dfa/passports/" target="_blank" rel="noopener noreferrer">Passport Service - Ireland.ie</a></li>
<li><a href="https://www.citizensinformation.ie/en/travel-and-recreation/passports/" target="_blank" rel="noopener noreferrer">Citizens Information - Passports</a></li>
<li><a href="https://www.dfa.ie/" target="_blank" rel="noopener noreferrer">Department of Foreign Affairs</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	55,
	'Passaporte Irlandês: Como Solicitar',
	$passport_content,
	'Guia para solicitar o passaporte irlandês. Documentos, taxas, prazos e processo online. Informação verificada em fontes oficiais.',
	array( 'documentos' )
);

// ============================================================================
// 11. CHILD BENEFIT (ID: 56)
// ============================================================================
$child_benefit_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Child Benefit?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Child Benefit</strong> é um pagamento mensal do governo irlandês para famílias com crianças menores de 16 anos (ou menores de 18 se estiverem em educação em tempo integral ou com deficiência).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem tem direito?</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Residentes <strong>habituais</strong> na Irlanda (morando aqui e com intenção de ficar por pelo menos 1 ano)</li>
<li>Famílias com crianças <strong>menores de 16 anos</strong></li>
<li>Crianças <strong>menores de 18 anos</strong> se estiverem em educação em tempo integral ou com deficiência</li>
<li>O solicitante deve ter <strong>PPS Number</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto é o valor?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O valor do Child Benefit é de <strong>€140 por mês</strong> para cada criança. O pagamento é feito no <strong>primeiro dia útil de cada mês</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o <strong>MyWelfare</strong> com seu MyGovID</li>
<li>Preencha o formulário de solicitação do Child Benefit</li>
<li>Informe os dados da criança (certidão de nascimento)</li>
<li>Envie a solicitação</li>
<li>Acompanhe o status pelo MyWelfare</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar Child Benefit:</strong> <a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">MyWelfare</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O Child Benefit é pago <strong>mensalmente</strong> no primeiro dia útil do mês</li>
<li>O pagamento é feito para <strong>um dos pais</strong> (geralmente a mãe)</li>
<li>Se você tem filhos no Brasil, pode ter direito ao Child Benefit se eles moram com você na Irlanda</li>
<li>O benefício <strong>não é afetado</strong> pela renda da família</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de visto para receber Child Benefit?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você precisa ser residente habitual na Irlanda. Ter um status migratório válido (como Employment Permit) ajuda a comprovar residência.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O Child Benefit é pago para crianças no Brasil?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não. O Child Benefit é pago para crianças que residem na Irlanda com você.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.gov.ie/en/service/child-benefit/" target="_blank" rel="noopener noreferrer">gov.ie - Child Benefit</a></li>
<li><a href="https://www.citizensinformation.ie/en/social-welfare/families-and-children/child-benefit/" target="_blank" rel="noopener noreferrer">Citizens Information - Child Benefit</a></li>
<li><a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">MyWelfare</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	56,
	'Child Benefit na Irlanda: Guia Completo',
	$child_benefit_content,
	'Guia sobre o Child Benefit na Irlanda. Valor, requisitos, como solicitar e informações verificadas em fontes oficiais.',
	array( 'beneficios', 'familia' )
);

// ============================================================================
// 12. SOCIAL WELFARE (ID: 57)
// ============================================================================
$welfare_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Social Welfare?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Social Welfare</strong> é o sistema de seguridade social irlandês, administrado pelo <strong>Department of Social Protection</strong>. Oferece diversos benefícios para trabalhadores, famílias e pessoas em situação de vulnerabilidade.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode acessar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os benefícios do Social Welfare estão disponíveis para <strong>residentes habituais</strong> na Irlanda. Alguns benefícios exigem contribuições de PRSI (benefits), enquanto outros são baseados em necessidade (allowances).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Principais benefícios</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Jobseeker's Benefit</strong>: Seguro-desemprego para quem contribuiu com PRSI</li>
<li><strong>Jobseeker's Allowance</strong>: Auxílio-desemprego baseado em necessidade</li>
<li><strong>Illness Benefit</strong>: Auxílio-doença para quem não pode trabalhar por motivo de saúde</li>
<li><strong>Maternity Benefit</strong>: Licença-maternidade paga</li>
<li><strong>Paternity Benefit</strong>: Licença-paternidade paga</li>
<li><strong>Child Benefit</strong>: Benefício mensal para famílias com crianças</li>
<li><strong>State Pension</strong>: Aposentadoria do estado</li>
<li><strong>Working Family Payment</strong>: Complemento de renda para famílias trabalhadoras</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o <strong>MyWelfare</strong> com seu MyGovID</li>
<li>Encontre o benefício que você precisa</li>
<li>Preencha o formulário online</li>
<li>Envie os documentos necessários</li>
<li>Acompanhe o status pelo MyWelfare</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar benefícios:</strong> <a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">MyWelfare</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Para benefícios baseados em PRSI (como Jobseeker's Benefit), você precisa ter <strong>contribuído</strong> por um período mínimo</li>
<li>Para benefícios baseados em necessidade (como Jobseeker's Allowance), você precisa passar por um <strong>teste de renda</strong></li>
<li>O <strong>PPS Number</strong> é obrigatório para solicitar qualquer benefício</li>
<li>Você precisa ser <strong>residente habitual</strong> na Irlanda</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem receber benefícios sociais na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, se você é residente habitual na Irlanda e cumpre os requisitos de cada benefício. Ter um Employment Permit ou outro status migratório válido é importante.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o MyGovID?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O MyGovID é o sistema de autenticação do governo irlandês. Você precisa dele para acessar o MyWelfare e outros serviços online do governo.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.mywelfare.ie/" target="_blank" rel="noopener noreferrer">MyWelfare</a></li>
<li><a href="https://www.gov.ie/en/department-of-social-protection/" target="_blank" rel="noopener noreferrer">Department of Social Protection</a></li>
<li><a href="https://www.citizensinformation.ie/en/social-welfare/" target="_blank" rel="noopener noreferrer">Citizens Information - Social Welfare</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	57,
	'Social Welfare na Irlanda: Guia de Benefícios',
	$welfare_content,
	'Guia sobre o Social Welfare na Irlanda. Benefícios disponíveis, requisitos, como solicitar e informações verificadas em fontes oficiais.',
	array( 'beneficios' )
);

// ============================================================================
// 13. OPENING A BUSINESS (ID: 58)
// ============================================================================
$business_content = <<<'HTML'
<!-- wp:heading --><h2>O que é preciso para abrir uma empresa na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Abrir uma empresa na Irlanda é um processo relativamente simples. O registro é feito no <strong>CRO (Companies Registration Office)</strong> e você precisa se registrar no <strong>Revenue</strong> para fins fiscais.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode abrir uma empresa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Qualquer pessoa com 18 anos ou mais pode abrir uma empresa na Irlanda, independentemente da nacionalidade. Brasileiros com status migratório válido podem abrir empresas.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Tipos de empresa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Sole Trader (Empresário Individual)</strong>: Você opera em seu próprio nome. Mais simples, mas você é pessoalmente responsável pelas dívidas.</li>
<li><strong>Limited Company (Sociedade Limitada)</strong>: Empresa separada de você. Mais proteção, mas mais burocracia.</li>
<li><strong>Partnership (Sociedade)</strong>: Dois ou mais sócios operam juntos.</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como abrir</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Escolha o tipo de empresa</li>
<li>Registre a empresa no <strong>CRO</strong> (para Limited Company)</li>
<li>Registre-se no <strong>Revenue</strong> para fins fiscais</li>
<li>Obtenha um <strong>PPS Number</strong> (se ainda não tem)</li>
<li>Abra uma <strong>conta bancária empresarial</strong></li>
<li>Contrate um <strong>contador</strong> (recomendado)</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O custo de registro de uma Limited Company no CRO é de <strong>€50</strong> (registro online). O registro de um Sole Trader no Revenue é <strong>gratuito</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde registrar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Registrar empresa:</strong> <a href="https://www.cro.ie/" target="_blank" rel="noopener noreferrer">CRO - Companies Registration Office</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Como <strong>Sole Trader</strong>, você é pessoalmente responsável pelas dívidas da empresa</li>
<li>Como <strong>Limited Company</strong>, a empresa é uma entidade separada - seus bens pessoais são protegidos</li>
<li>Você precisa declarar impostos anualmente (Form 11 para Sole Trader, CT1 para Limited Company)</li>
<li>Contrate um <strong>contador</strong> para lidar com as obrigações fiscais</li>
<li>O <strong>Revenue</strong> pode multar quem não declara impostos corretamente</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem abrir empresa na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, desde que tenham status migratório válido. Brasileiros com Stamp 1 (trabalho) ou Stamp 4 (residência) podem abrir empresas.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Preciso de visto de empreendedor?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Depende do seu status migratório. Se você já tem Stamp 4, pode abrir empresa. Se está com Stamp 1, pode precisar de permissão específica. Consulte o Department of Justice.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.cro.ie/" target="_blank" rel="noopener noreferrer">CRO - Companies Registration Office</a></li>
<li><a href="https://www.revenue.ie/" target="_blank" rel="noopener noreferrer">Revenue - Office of the Revenue Commissioners</a></li>
<li><a href="https://www.citizensinformation.ie/en/employment/types-of-employment/self-employment/" target="_blank" rel="noopener noreferrer">Citizens Information - Self-Employment</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	58,
	'Abrir uma Empresa na Irlanda: Guia Completo',
	$business_content,
	'Guia para abrir empresa na Irlanda. Tipos de empresa, registro no CRO, obrigações fiscais e informações verificadas em fontes oficiais.',
	array( 'negocios' )
);

// ============================================================================
// 14. VISAS & IMMIGRATION (ID: 59)
// ============================================================================
$visa_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o sistema de imigração irlandês?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O sistema de imigração irlandês é administrado pelo <strong>Department of Justice</strong> e pelo <strong>Irish Immigration Service (ISD)</strong>. Brasileiros <strong>não precisam de visto</strong> para entrar na Irlanda como turistas (até 90 dias), mas precisam de permissão para trabalhar ou residir por mais tempo.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa de visto?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Brasileiros <strong>não precisam de visto</strong> para entrar na Irlanda como turistas por até 90 dias. No entanto, para <strong>trabalhar</strong>, <strong>estudar</strong> ou <strong>residir</strong> por mais de 90 dias, você precisa de uma permissão adequada.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Tipos de permissão (Stamps)</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Stamp 1</strong>: Permissão de trabalho (Employment Permit)</li>
<li><strong>Stamp 2</strong>: Estudante (Student)</li>
<li><strong>Stamp 2A</strong>: Estudante sem direito a trabalho</li>
<li><strong>Stamp 3</strong>: Visitante/Familiar (não pode trabalhar)</li>
<li><strong>Stamp 4</strong>: Residência (pode trabalhar sem permissão separada)</li>
<li><strong>Stamp 5</strong>: Residência sem limite de tempo</li>
<li><strong>Stamp 6</strong>: Cidadão irlandês com dupla cidadania</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como obter permissão de trabalho</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para trabalhar na Irlanda, você geralmente precisa de um <strong>Employment Permit</strong>. O processo é:</p><!-- /wp:list -->
<!-- wp:list --><ol>
<li>Encontre um emprego com um empregador irlandês</li>
<li>O empregador solicita o <strong>Employment Permit</strong> ao Department of Enterprise</li>
<li>Com o permit aprovado, você pode entrar na Irlanda</li>
<li>Registre-se no <strong>Immigration Office</strong> e receba seu <strong>IRP (Irish Residence Permit)</strong></li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>O que é o IRP?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>IRP (Irish Residence Permit)</strong> é o documento que comprova sua residência legal na Irlanda. É um cartão com seus dados e seu status migratório (Stamp). Você precisa renová-lo periodicamente.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como registrar-se na imigração</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Agende uma visita ao <strong>Immigration Office</strong> (em Dublin) ou à <strong>Garda National Immigration Bureau (GNIB)</strong> (fora de Dublin)</li>
<li>Leve seu passaporte, Employment Permit, comprovante de endereço e foto</li>
<li>Pague a taxa de registro</li>
<li>Receba seu IRP</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa de registro de imigração (IRP) é de <strong>€300</strong> por ano.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Informações de imigração:</strong> <a href="https://www.irishimmigration.ie/" target="_blank" rel="noopener noreferrer">Irish Immigration Service</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Você deve se registrar na imigração <strong>dentro de 90 dias</strong> após chegar na Irlanda (se vai ficar mais de 90 dias)</li>
<li>O IRP deve ser <strong>renovado anualmente</strong></li>
<li>Não trabalhe sem a permissão adequada - isso pode levar à <strong>deportação</strong></li>
<li>Se você perdeu o emprego, informe o Department of Justice imediatamente</li>
<li>O <strong>Stamp 4</strong> permite trabalhar sem permissão separada</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros precisam de visto para a Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não para turismo (até 90 dias). Para trabalhar ou estudar, você precisa de permissão adequada (Employment Permit ou Student Visa).</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o Stamp 4?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O Stamp 4 é uma permissão de residência que permite trabalhar sem precisar de um Employment Permit separado. É concedido em várias situações, como após 2 anos com Stamp 1 ou para familiares de cidadãos irlandeses.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto custa o IRP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa de registro do IRP é de €300 por ano.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.irishimmigration.ie/" target="_blank" rel="noopener noreferrer">Irish Immigration Service</a></li>
<li><a href="https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/" target="_blank" rel="noopener noreferrer">Department of Justice</a></li>
<li><a href="https://www.citizensinformation.ie/en/moving-country/" target="_blank" rel="noopener noreferrer">Citizens Information - Moving Country</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	59,
	'Vistos e Imigração na Irlanda: Guia Completo',
	$visa_content,
	'Guia sobre vistos e imigração na Irlanda. Tipos de permissão (Stamps), Employment Permit, IRP e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// ============================================================================
// 15. IRP RENEWAL (ID: 60)
// ============================================================================
$irp_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o IRP?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>IRP (Irish Residence Permit)</strong> é o documento que comprova sua residência legal na Irlanda. É um cartão com seus dados pessoais, foto e seu status migratório (Stamp). Você precisa renová-lo <strong>anualmente</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa renovar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os não-EEA (não europeus) que residem na Irlanda com permissão de residência precisam renovar o IRP. Brasileiros com Stamp 1, Stamp 2, Stamp 3 ou Stamp 4 precisam renovar.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que você precisa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Passaporte</strong> válido</li>
<li><strong>IRP atual</strong> (se tiver)</li>
<li><strong>Comprovante de endereço</strong> na Irlanda</li>
<li><strong>Comprovante de status</strong>: Employment Permit, carta da escola, etc.</li>
<li><strong>Foto</strong> nos padrões exigidos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como renovar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>Irish Immigration Service</strong></li>
<li>Preencha o formulário de renovação online</li>
<li>Envie os documentos necessários</li>
<li>Pague a taxa de €300</li>
<li>Acompanhe o status da solicitação</li>
<li>Receba seu novo IRP pelo correio</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa de renovação do IRP é de <strong>€300</strong> por ano.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde renovar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Renovar IRP:</strong> <a href="https://www.irishimmigration.ie/registration-renewal/" target="_blank" rel="noopener noreferrer">Irish Immigration - Registration Renewal</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Renove seu IRP <strong>antes</strong> que expire</li>
<li>Se seu IRP expirou, você pode estar <strong>irregular</strong> na Irlanda</li>
<li>O IRP é <strong>pessoal e intransferível</strong></li>
<li>Guarde seu IRP em local seguro - é seu documento de residência</li>
<li>Se você perdeu o emprego, informe o Department of Justice imediatamente</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Quanto tempo leva para renovar o IRP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O processamento pode levar de algumas semanas a alguns meses, dependendo do volume de solicitações. Solicite com antecedência.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que acontece se meu IRP expirar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você pode estar irregular na Irlanda. Solicite a renovação o mais rápido possível e explique sua situação ao Department of Justice.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.irishimmigration.ie/registration-renewal/" target="_blank" rel="noopener noreferrer">Irish Immigration - Registration Renewal</a></li>
<li><a href="https://www.irishimmigration.ie/registration/" target="_blank" rel="noopener noreferrer">Irish Immigration - Registration</a></li>
<li><a href="https://www.gov.ie/en/department-of-justice-home-affairs-and-migration/" target="_blank" rel="noopener noreferrer">Department of Justice</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_update_guide(
	60,
	'Renovação do IRP na Irlanda: Guia Completo',
	$irp_content,
	'Guia para renovação do IRP (Irish Residence Permit) na Irlanda. Documentos, taxas, processo e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// ============================================================================
// NEW GUIDES
// ============================================================================

// 16. MyGovID
$mygovid_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o MyGovID?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>MyGovID</strong> é o sistema de autenticação do governo irlandês. É como um "login único" que permite acessar vários serviços públicos online com uma única conta. É essencial para acessar o <strong>MyWelfare</strong>, o <strong>Revenue</strong> e outros serviços do governo.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa do MyGovID?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Qualquer pessoa que precise acessar serviços públicos online na Irlanda, incluindo:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Solicitar benefícios sociais (MyWelfare)</li>
<li>Acessar o Revenue (impostos)</li>
<li>Solicitar o PPS Number</li>
<li>Acessar serviços de saúde</li>
<li>Renovar documentos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Níveis do MyGovID</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Basic</strong>: Conta básica com email e senha. Permite acessar alguns serviços.</li>
<li><strong>Verified</strong>: Conta verificada com documento de identidade. Permite acessar a maioria dos serviços.</li>
<li><strong>Mobile</strong>: Verificação adicional com app móvel para serviços mais sensíveis.</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como criar uma conta</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>MyGovID</strong></li>
<li>Clique em "Create Account"</li>
<li>Informe seu email e crie uma senha</li>
<li>Verifique seu email</li>
<li>Para verificar sua identidade, você precisará do seu <strong>PPS Number</strong> e de um documento de identidade</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde criar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Criar conta MyGovID:</strong> <a href="https://www.mygovid.ie/" target="_blank" rel="noopener noreferrer">MyGovID</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O MyGovID é <strong>gratuito</strong></li>
<li>Você precisa de <strong>PPS Number</strong> para verificar sua conta</li>
<li>Guarde sua senha em local seguro - é o acesso a todos os seus serviços públicos</li>
<li>Nunca compartilhe sua senha com terceiros</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de PPS Number para criar MyGovID?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, para verificar sua conta e acessar a maioria dos serviços, você precisa do PPS Number.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O MyGovID é gratuito?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, criar e usar o MyGovID é gratuito.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.mygovid.ie/" target="_blank" rel="noopener noreferrer">MyGovID</a></li>
<li><a href="https://www.citizensinformation.ie/en/government-in-ireland/how-government-works/egovernment/mygovid/" target="_blank" rel="noopener noreferrer">Citizens Information - MyGovID</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'MyGovID na Irlanda: Como Criar e Usar',
	'mygovid',
	$mygovid_content,
	'Guia sobre o MyGovID na Irlanda. O que é, como criar conta, níveis de verificação e serviços acessíveis. Informação verificada em fontes oficiais.',
	array( 'documentos' )
);

// 17. Employment Rights
$employment_content = <<<'HTML'
<!-- wp:heading --><h2>O que são os direitos trabalhistas na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os direitos trabalhistas na Irlanda são protegidos por lei e administrados pelo <strong>Workplace Relations Commission (WRC)</strong>. Todos os trabalhadores, incluindo brasileiros com permissão de trabalho, têm direitos garantidos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem tem direitos trabalhistas?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os trabalhadores na Irlanda, independentemente da nacionalidade, têm direitos trabalhistas. Isso inclui salário mínimo, férias, horas de trabalho e proteção contra discriminação.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Salário mínimo</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O salário mínimo nacional na Irlanda é de <strong>€13,50 por hora</strong> (a partir de janeiro de 2026). Existem taxas reduzidas para menores de 20 anos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Férias (Annual Leave)</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Todos os trabalhadores têm direito a <strong>4 semanas de férias pagas</strong> por ano</li>
<li>As férias são acumuladas proporcionalmente ao tempo trabalhado</li>
<li>O empregador deve pagar as férias não utilizadas ao término do contrato</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Horas de trabalho</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>A jornada máxima é de <strong>48 horas por semana</strong> (em média)</li>
<li>Você tem direito a <strong>11 horas de descanso</strong> entre turnos</li>
<li>Você tem direito a <strong>1 dia de descanso</strong> por semana</li>
<li>Horas extras devem ser pagas ou compensadas</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Contrato de trabalho</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Seu empregador deve fornecer um <strong>contrato de trabalho por escrito</strong> dentro de 5 dias após o início do trabalho. O contrato deve incluir salário, horas, férias e período de aviso prévio.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Aviso prévio</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O período de aviso prévio depende do tempo de serviço:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>1-2 anos: 1 semana</li>
<li>2-5 anos: 2 semanas</li>
<li>5-10 anos: 4 semanas</li>
<li>10+ anos: 8 semanas</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Onde buscar ajuda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Se você tem problemas no trabalho, contate o <strong>Workplace Relations Commission (WRC)</strong>:</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>WRC:</strong> <a href="https://www.workplacerelations.ie/" target="_blank" rel="noopener noreferrer">workplacerelations.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O salário mínimo se aplica a <strong>todos</strong> os trabalhadores, incluindo estrangeiros</li>
<li>Você tem direito a <strong>recibos de pagamento</strong> (payslips) detalhados</li>
<li>É <strong>ilegal</strong> discriminar com base em nacionalidade, raça, gênero, etc.</li>
<li>Se você foi demitido injustamente, pode recorrer ao WRC</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Qual é o salário mínimo na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O salário mínimo nacional é de €13,50 por hora (a partir de janeiro de 2026). Verifique o valor atual no site do governo.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quantas férias tenho direito?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os trabalhadores têm direito a 4 semanas de férias pagas por ano.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o WRC?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O WRC (Workplace Relations Commission) é o órgão oficial que lida com disputas trabalhistas, inspeções e informações sobre direitos no trabalho.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.workplacerelations.ie/" target="_blank" rel="noopener noreferrer">Workplace Relations Commission</a></li>
<li><a href="https://www.citizensinformation.ie/en/employment/employment-rights-and-conditions/" target="_blank" rel="noopener noreferrer">Citizens Information - Employment Rights</a></li>
<li><a href="https://www.gov.ie/en/publication/3a4d5-national-minimum-wage/" target="_blank" rel="noopener noreferrer">gov.ie - National Minimum Wage</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Direitos Trabalhistas na Irlanda: Guia Completo',
	'direitos-trabalhistas',
	$employment_content,
	'Guia sobre direitos trabalhistas na Irlanda. Salário mínimo, férias, horas de trabalho, aviso prévio e informações verificadas em fontes oficiais.',
	array( 'empregos' )
);

// 18. Public Transport
$transport_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o transporte público na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O transporte público na Irlanda é administrado pela <strong>National Transport Authority (NTA)</strong> e operado por várias empresas. O sistema inclui ônibus, trem (DART, Luas, Intercity) e serviços regionais.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem usa transporte público?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O transporte público é usado por moradores e visitantes em toda a Irlanda. Em Dublin, o sistema é mais completo, com ônibus, Luas (trem leve) e DART (trem costeiro).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Principais serviços</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Dublin Bus</strong>: Ônibus em Dublin</li>
<li><strong>Luas</strong>: Trem leve em Dublin</li>
<li><strong>DART</strong>: Trem costeiro em Dublin</li>
<li><strong>Irish Rail</strong>: Trens interurbanos</li>
<li><strong>Bus Éireann</strong>: Ônibus interurbanos e rurais</li>
<li><strong>Local Link</strong>: Ônibus rurais</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como pagar</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Leap Card</strong>: Cartão de transporte recarregável com tarifas reduzidas</li>
<li><strong>TFI Go</strong>: App de pagamento móvel</li>
<li>Pagamento em dinheiro (tarifas mais altas)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que é a Leap Card?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A <strong>Leap Card</strong> é o cartão de transporte público da Irlanda. Você pode comprar e recarregar em estações, lojas conveniadas e online. Com a Leap Card, as tarifas são mais baratas que pagar em dinheiro.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde comprar a Leap Card</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Comprar Leap Card:</strong> <a href="https://www.leapcard.ie/" target="_blank" rel="noopener noreferrer">Leap Card</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>A Leap Card custa <strong>€5</strong> (taxa única do cartão)</li>
<li>Você pode recarregar online, em estações ou em lojas conveniadas</li>
<li>Estudantes podem ter <strong>descontos</strong> com o Student Leap Card</li>
<li>O <strong>TFI</strong> (Transport for Ireland) é o portal oficial de informações de transporte</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>O que é a Leap Card?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É o cartão de transporte público recarregável da Irlanda. Oferece tarifas reduzidas em ônibus, trem e Luas.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Preciso de Leap Card?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não é obrigatória, mas as tarifas são mais baratas com a Leap Card do que pagando em dinheiro.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.transportforireland.ie/" target="_blank" rel="noopener noreferrer">Transport for Ireland</a></li>
<li><a href="https://www.nationaltransport.ie/" target="_blank" rel="noopener noreferrer">National Transport Authority</a></li>
<li><a href="https://www.leapcard.ie/" target="_blank" rel="noopener noreferrer">Leap Card</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Transporte Público na Irlanda: Guia Completo',
	'transporte-publico',
	$transport_content,
	'Guia sobre transporte público na Irlanda. Ônibus, trem, Luas, Leap Card e informações verificadas em fontes oficiais.',
	array( 'transporte' )
);

// 19. SUSI
$susi_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o SUSI?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>SUSI (Student Universal Support Ireland)</strong> é o órgão oficial que administra as bolsas de estudo (student grants) para estudantes de nível superior na Irlanda. As bolsas ajudam a pagar taxas e custos de manutenção.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode solicitar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para solicitar o SUSI, você precisa:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Ser <strong>residente habitual</strong> na Irlanda (morando aqui por pelo menos 3 dos últimos 5 anos)</li>
<li>Estar matriculado em um <strong>curso elegível</strong> (geralmente nível superior)</li>
<li>Passar no <strong>teste de renda</strong> (income assessment)</li>
<li>Ter <strong>PPS Number</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Tipos de bolsa</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Maintenance Grant</strong>: Para custos de manutenção (moradia, alimentação)</li>
<li><strong>Fee Grant</strong>: Para pagar taxas de matrícula</li>
<li><strong>Field Trip Grant</strong>: Para viagens de estudo</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>SUSI</strong></li>
<li>Crie uma conta</li>
<li>Preencha o formulário online</li>
<li>Envie os documentos necessários (comprovante de renda, residência, etc.)</li>
<li>Acompanhe o status da solicitação</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar bolsa SUSI:</strong> <a href="https://www.susi.ie/" target="_blank" rel="noopener noreferrer">SUSI</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O prazo de solicitação geralmente é de <strong>abril a novembro</strong> para o ano acadêmico seguinte</li>
<li>O teste de renda considera a renda dos pais (se você é dependente) ou sua própria renda</li>
<li>Os limites de renda variam conforme o tamanho da família</li>
<li>Você precisa ser <strong>residente habitual</strong> - brasileiros recém-chegados podem não ser elegíveis imediatamente</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem receber bolsa SUSI?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, se você é residente habitual na Irlanda (geralmente 3 dos últimos 5 anos) e cumpre os requisitos de renda e curso.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quando devo solicitar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O prazo principal é de abril a novembro para o ano acadêmico seguinte. Solicite o mais cedo possível.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.susi.ie/" target="_blank" rel="noopener noreferrer">SUSI - Student Universal Support Ireland</a></li>
<li><a href="https://www.citizensinformation.ie/en/education/third-level-education/fees-and-supports-for-third-level-education/student-grant-scheme/" target="_blank" rel="noopener noreferrer">Citizens Information - Student Grant Scheme</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'SUSI na Irlanda: Como Solicitar Bolsa de Estudos',
	'susi',
	$susi_content,
	'Guia sobre o SUSI na Irlanda. Quem pode solicitar, tipos de bolsa, como aplicar e informações verificadas em fontes oficiais.',
	array( 'educacao' )
);

// 20. Emergency Services
$emergency_content = <<<'HTML'
<!-- wp:heading --><h2>O que são os serviços de emergência na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Na Irlanda, os serviços de emergência são acessados pelo número <strong>112</strong> ou <strong>999</strong>. Ambos são gratuitos e funcionam 24 horas por dia, 7 dias por semana.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa saber?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os residentes e visitantes na Irlanda devem saber como acessar os serviços de emergência. Em caso de emergência médica, incêndio, crime ou acidente, ligue 112 ou 999.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Números de emergência</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>112</strong>: Número de emergência europeu (funciona em toda a UE)</li>
<li><strong>999</strong>: Número de emergência irlandês</li>
<li><strong>112/999</strong>: Polícia (Garda), ambulância, bombeiros, resgate marítimo</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como ligar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Ligue <strong>112</strong> ou <strong>999</strong></li>
<li>Informe qual serviço você precisa (polícia, ambulância, bombeiros)</li>
<li>Informe sua localização (endereço ou Eircode)</li>
<li>Descreva a emergência</li>
<li>Não desligue até que o operador autorize</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>O que é o Eircode?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Eircode</strong> é o código postal irlandês (7 caracteres, ex: D01 F5P2). Todo endereço na Irlanda tem um Eircode único. Em emergências, informar seu Eircode ajuda os serviços a encontrar você rapidamente.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>As ligações para 112/999 são <strong>gratuitas</strong></li>
<li>Você pode ligar de qualquer telefone, incluindo celular sem crédito</li>
<li>O operador pode falar <strong>inglês</strong> - tenha o básico pronto</li>
<li>Se você não fala inglês, diga o nome do seu idioma e peça um intérprete</li>
<li>Em emergências médicas não urgentes, ligue para seu <strong>GP</strong> ou para o <strong>HSE</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Qual número devo ligar em emergência?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ligue 112 ou 999. Ambos funcionam e são gratuitos.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o Eircode?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É o código postal irlandês. Todo endereço tem um Eircode único de 7 caracteres. Você pode encontrar o Eircode de um endereço no site eircode.ie.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.hse.ie/eng/services/list/4/healthservices/emergency/" target="_blank" rel="noopener noreferrer">HSE - Emergency Services</a></li>
<li><a href="https://www.citizensinformation.ie/en/health/health-services/emergency-health-services/" target="_blank" rel="noopener noreferrer">Citizens Information - Emergency Health Services</a></li>
<li><a href="https://www.eircode.ie/" target="_blank" rel="noopener noreferrer">Eircode</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Serviços de Emergência na Irlanda: 112 e 999',
	'servicos-emergencia',
	$emergency_content,
	'Guia sobre serviços de emergência na Irlanda. Números 112 e 999, como ligar, Eircode e informações verificadas em fontes oficiais.',
	array( 'saude' )
);

// 21. NCT
$nct_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o NCT?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>NCT (National Car Test)</strong> é a inspeção técnica obrigatória para veículos na Irlanda. É administrado pela <strong>RSA (Road Safety Authority)</strong> e verifica a segurança e as emissões do veículo.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa fazer o NCT?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Carros com mais de <strong>4 anos</strong> precisam fazer o NCT. A inspeção é obrigatória e o veículo deve passar no teste para poder circular legalmente.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quando fazer</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Carros com 4-9 anos: NCT a cada <strong>2 anos</strong></li>
<li>Carros com 10+ anos: NCT <strong>anualmente</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>O que é verificado</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Freios</li>
<li>Pneus</li>
<li>Luzes</li>
<li>Emissões</li>
<li>Direção</li>
<li>Suspensão</li>
<li>Carroceria</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa do NCT é de <strong>€55</strong> para carros (inspeção completa). A reinspeção (retest) custa <strong>€28</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde fazer</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Agendar NCT:</strong> <a href="https://www.ncts.ie/" target="_blank" rel="noopener noreferrer">NCT - National Car Test</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Dirigir sem NCT válido é <strong>ilegal</strong> e pode resultar em multa</li>
<li>O NCT é obrigatório para <strong>renovar o motor tax</strong></li>
<li>Se o carro não passar, você tem um prazo para corrigir e fazer a reinspeção</li>
<li>O certificado NCT é válido por 1 ou 2 anos, dependendo da idade do carro</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de NCT para comprar um carro?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Não é obrigatório para comprar, mas o carro precisa ter NCT válido para circular legalmente. Verifique se o carro tem NCT antes de comprar.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto custa o NCT?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>A inspeção completa custa €55. A reinspeção custa €28.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.ncts.ie/" target="_blank" rel="noopener noreferrer">NCT - National Car Test</a></li>
<li><a href="https://www.rsa.ie/" target="_blank" rel="noopener noreferrer">RSA - Road Safety Authority</a></li>
<li><a href="https://www.citizensinformation.ie/en/travel-and-recreation/motoring/buying-or-selling-a-vehicle/national-car-test/" target="_blank" rel="noopener noreferrer">Citizens Information - NCT</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'NCT na Irlanda: Guia da Inspeção Técnica',
	'nct',
	$nct_content,
	'Guia sobre o NCT (National Car Test) na Irlanda. Quem precisa, quando fazer, custos e informações verificadas em fontes oficiais.',
	array( 'transporte' )
);

// 22. Motor Tax
$motortax_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Motor Tax?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Motor Tax</strong> é o imposto anual de circulação de veículos na Irlanda. Todo veículo registrado na Irlanda deve ter o motor tax pago para circular legalmente.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa pagar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os proprietários de veículos registrados na Irlanda precisam pagar o motor tax anualmente. O valor varia conforme o tipo de veículo, tamanho do motor e emissões de CO2.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como pagar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site <strong>motortax.ie</strong></li>
<li>Informe o número de registro do veículo</li>
<li>Verifique os dados do veículo</li>
<li>Escolha o período (3, 6 ou 12 meses)</li>
<li>Pague com cartão de crédito/débito</li>
<li>Receba o comprovante</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O valor do motor tax varia conforme o veículo. Para carros, o valor é baseado nas emissões de CO2. Carros com emissões mais baixas pagam menos. Os valores variam de aproximadamente <strong>€180 a €2.400</strong> por ano.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde pagar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Pagar Motor Tax:</strong> <a href="https://www.motortax.ie/" target="_blank" rel="noopener noreferrer">Motor Tax Online</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Dirigir sem motor tax é <strong>ilegal</strong> e pode resultar em multa</li>
<li>Você precisa de <strong>NCT válido</strong> para renovar o motor tax</li>
<li>O motor tax pode ser pago por <strong>3, 6 ou 12 meses</strong></li>
<li>Pagar por 12 meses é mais barato que pagar por 3 meses</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de NCT para pagar motor tax?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, para carros com mais de 4 anos, você precisa de NCT válido para renovar o motor tax.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto custa o motor tax?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O valor varia conforme o veículo. Para carros, é baseado nas emissões de CO2. Verifique o valor exato no site motortax.ie.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.motortax.ie/" target="_blank" rel="noopener noreferrer">Motor Tax Online</a></li>
<li><a href="https://www.citizensinformation.ie/en/travel-and-recreation/motoring/motor-tax-and-insurance/motor-tax-rates/" target="_blank" rel="noopener noreferrer">Citizens Information - Motor Tax Rates</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Motor Tax na Irlanda: Como Pagar',
	'motor-tax',
	$motortax_content,
	'Guia sobre o Motor Tax na Irlanda. Como pagar, valores, requisitos e informações verificadas em fontes oficiais.',
	array( 'transporte' )
);

// 23. Eircode
$eircode_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Eircode?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Eircode</strong> é o código postal irlandês. É um código único de 7 caracteres (ex: D01 F5P2) atribuído a cada endereço na Irlanda. Foi introduzido em 2015 e é essencial para entregas, emergências e serviços.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa de um Eircode?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os residentes e empresas na Irlanda têm um Eircode. Você precisa dele para:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Receber correspondência e encomendas</li>
<li>Informar seu endereço em serviços públicos</li>
<li>Emergências (112/999)</li>
<li>Registrar-se em serviços (bancos, médicos, etc.)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como encontrar seu Eircode</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site <strong>eircode.ie</strong></li>
<li>Digite seu endereço na busca</li>
<li>Encontre o Eircode correspondente</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde encontrar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Encontrar Eircode:</strong> <a href="https://www.eircode.ie/" target="_blank" rel="noopener noreferrer">Eircode</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Cada endereço tem um Eircode <strong>único</strong></li>
<li>O Eircode é <strong>gratuito</strong> para encontrar</li>
<li>Use seu Eircode em <strong>todas</strong> as correspondências e formulários</li>
<li>Em emergências, informar seu Eircode ajuda os serviços a encontrar você rapidamente</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>O que é o Eircode?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É o código postal irlandês, um código único de 7 caracteres para cada endereço na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Como encontro meu Eircode?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Acesse o site eircode.ie e busque seu endereço. O Eircode será exibido.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.eircode.ie/" target="_blank" rel="noopener noreferrer">Eircode</a></li>
<li><a href="https://www.citizensinformation.ie/en/consumer/phones-internet-tv-and-postal-services/postal-services/" target="_blank" rel="noopener noreferrer">Citizens Information - Postal Services</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Eircode na Irlanda: O que é e Como Encontrar',
	'eircode',
	$eircode_content,
	'Guia sobre o Eircode na Irlanda. O que é, como encontrar, por que é importante e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// 24. HAP
$hap_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o HAP?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>HAP (Housing Assistance Payment)</strong> é um programa do governo irlandês que ajuda pessoas elegíveis a pagar o aluguel. O HAP é administrado pelos <strong>conselhos locais</strong> (local authorities).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem pode solicitar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O HAP é destinado a pessoas que:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Estão na <strong>lista de espera de habitação social</strong> (social housing waiting list)</li>
<li>Têm <strong>renda baixa</strong></li>
<li>São <strong>residentes habituais</strong> na Irlanda</li>
<li>Não possuem moradia adequada</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como funciona</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Com o HAP, o conselho local paga o aluguel diretamente ao locador. O inquilino paga uma contribuição ao conselho (geralmente entre 15% e 35% da renda). O inquilino encontra o imóvel no mercado privado.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como solicitar</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Entre em contato com o <strong>conselho local</strong> da sua área</li>
<li>Solicite a avaliação para o HAP</li>
<li>Se aprovado, encontre um imóvel no mercado privado</li>
<li>O conselho verifica o imóvel e o locador</li>
<li>O conselho começa a pagar o aluguel ao locador</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Informações sobre HAP:</strong> <a href="https://www.gov.ie/en/service/76f3e-housing-assistance-payment/" target="_blank" rel="noopener noreferrer">gov.ie - HAP</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O HAP é para pessoas na <strong>lista de espera de habitação social</strong></li>
<li>Você precisa encontrar o imóvel <strong>você mesmo</strong> no mercado privado</li>
<li>O conselho local paga o aluguel <strong>diretamente ao locador</strong></li>
<li>Você paga uma <strong>contribuição</strong> ao conselho baseada na sua renda</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem solicitar HAP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, se você é residente habitual na Irlanda e está na lista de espera de habitação social. Ter status migratório válido é importante.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quanto pago com o HAP?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você paga uma contribuição baseada na sua renda, geralmente entre 15% e 35% da sua renda semanal.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.gov.ie/en/service/76f3e-housing-assistance-payment/" target="_blank" rel="noopener noreferrer">gov.ie - HAP</a></li>
<li><a href="https://www.citizensinformation.ie/en/housing/renting-a-home/housing-assistance-payment/" target="_blank" rel="noopener noreferrer">Citizens Information - HAP</a></li>
<li><a href="https://www.gov.ie/en/help/local-authorities/" target="_blank" rel="noopener noreferrer">Local Authorities</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'HAP na Irlanda: Auxílio para Pagar Aluguel',
	'hap',
	$hap_content,
	'Guia sobre o HAP (Housing Assistance Payment) na Irlanda. Quem pode solicitar, como funciona e informações verificadas em fontes oficiais.',
	array( 'moradia', 'beneficios' )
);

// 25. RTB
$rtb_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o RTB?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>RTB (Residential Tenancies Board)</strong> é o órgão oficial que regula o aluguel residencial na Irlanda. Ele registra contratos de arrendamento, resolve disputas entre inquilinos e locadores, e fornece informações sobre direitos e deveres.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa conhecer o RTB?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os inquilinos e locadores na Irlanda. O RTB é essencial para proteger seus direitos como inquilino.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>O que o RTB faz</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Registra contratos</strong> de arrendamento</li>
<li><strong>Resolve disputas</strong> entre inquilinos e locadores</li>
<li><strong>Fornece informações</strong> sobre direitos e deveres</li>
<li><strong>Regula aumentos</strong> de aluguel</li>
<li><strong>Administra o depósito</strong> (em alguns casos)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Seus direitos como inquilino</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O locador deve <strong>registrar o contrato</strong> no RTB dentro de 1 mês</li>
<li>O depósito máximo é de <strong>2 meses de aluguel</strong></li>
<li>O locador deve dar <strong>aviso prévio</strong> para terminar o contrato</li>
<li>Em <strong>Rent Pressure Zones</strong>, o aumento de aluguel é limitado</li>
<li>O locador deve fornecer um <strong>recibo</strong> do depósito</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como registrar uma disputa</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>RTB</strong></li>
<li>Preencha o formulário de disputa</li>
<li>Pague a taxa (se aplicável)</li>
<li>O RTB analisa o caso</li>
<li>Participe da mediação ou audiência</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>RTB:</strong> <a href="https://www.rtb.ie/" target="_blank" rel="noopener noreferrer">rtb.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Verifique se seu contrato está <strong>registrado no RTB</strong></li>
<li>Guarde <strong>todos os recibos</strong> de pagamento</li>
<li>Se o locador não devolver o depósito, você pode <strong>recorrer ao RTB</strong></li>
<li>O RTB tem <strong>poder de decisão</strong> em disputas</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>O que é o RTB?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É o órgão oficial que regula o aluguel residencial na Irlanda. Registra contratos e resolve disputas.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Meu locador precisa registrar o contrato?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, o locador deve registrar o contrato no RTB dentro de 1 mês após o início do arrendamento.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.rtb.ie/" target="_blank" rel="noopener noreferrer">RTB - Residential Tenancies Board</a></li>
<li><a href="https://www.citizensinformation.ie/en/housing/renting-a-home/" target="_blank" rel="noopener noreferrer">Citizens Information - Renting a Home</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'RTB na Irlanda: Seus Direitos como Inquilino',
	'rtb',
	$rtb_content,
	'Guia sobre o RTB (Residential Tenancies Board) na Irlanda. Seus direitos como inquilino, como registrar disputas e informações verificadas em fontes oficiais.',
	array( 'moradia' )
);

// 26. CAO
$cao_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o CAO?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>CAO (Central Applications Office)</strong> é o órgão central que processa as solicitações de admissão para cursos de nível superior (universidades e institutos) na Irlanda. É o sistema pelo qual você se candidata a cursos de graduação.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa usar o CAO?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Estudantes que desejam ingressar em cursos de graduação (undergraduate) em universidades e institutos de tecnologia na Irlanda. O CAO é usado tanto por estudantes irlandeses quanto internacionais.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Como funciona</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Crie uma conta no site do <strong>CAO</strong></li>
<li>Escolha até <strong>10 cursos</strong> em ordem de preferência</li>
<li>Pague a taxa de solicitação</li>
<li>Envie a documentação necessária</li>
<li>Aguarde as ofertas (offers) em agosto</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Prazos importantes</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>1 de fevereiro</strong>: Prazo normal para solicitação</li>
<li><strong>1 de maio</strong>: Prazo para mudar preferências</li>
<li><strong>Agosto</strong>: Ofertas (offers) são feitas</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Quanto custa?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A taxa de solicitação do CAO é de <strong>€45</strong> (para 10 cursos) ou <strong>€30</strong> (para solicitações tardias).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde solicitar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Solicitar pelo CAO:</strong> <a href="https://www.cao.ie/" target="_blank" rel="noopener noreferrer">CAO - Central Applications Office</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O CAO é usado para cursos de <strong>graduação</strong> (undergraduate)</li>
<li>Para pós-graduação, você se candidata <strong>diretamente</strong> à universidade</li>
<li>Estudantes internacionais podem ter requisitos <strong>adicionais</strong> (inglês, equivalência de notas)</li>
<li>As ofertas são baseadas em <strong>pontos</strong> (Leaving Certificate ou equivalente)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Brasileiros podem usar o CAO?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, estudantes internacionais podem se candidatar através do CAO. Você precisará de equivalência de notas e comprovação de proficiência em inglês.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Quando devo solicitar?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>O prazo normal é 1 de fevereiro para o ano acadêmico seguinte. Solicite o mais cedo possível.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.cao.ie/" target="_blank" rel="noopener noreferrer">CAO - Central Applications Office</a></li>
<li><a href="https://www.citizensinformation.ie/en/education/third-level-education/applying-to-college/central-applications-office/" target="_blank" rel="noopener noreferrer">Citizens Information - CAO</a></li>
<li><a href="https://hea.ie/" target="_blank" rel="noopener noreferrer">Higher Education Authority</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'CAO na Irlanda: Como se Candidatar à Universidade',
	'cao',
	$cao_content,
	'Guia sobre o CAO (Central Applications Office) na Irlanda. Como se candidatar, prazos, taxas e informações verificadas em fontes oficiais.',
	array( 'educacao' )
);

// 27. Consumer Rights
$consumer_content = <<<'HTML'
<!-- wp:heading --><h2>O que são os direitos do consumidor na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Os direitos do consumidor na Irlanda são protegidos por lei e administrados pelo <strong>CCPC (Competition and Consumer Protection Commission)</strong>. Todos os consumidores, incluindo brasileiros, têm direitos garantidos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem tem direitos do consumidor?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os consumidores na Irlanda, independentemente da nacionalidade. Se você compra produtos ou serviços na Irlanda, seus direitos são protegidos.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Principais direitos</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Direito de devolução</strong>: Você pode devolver produtos com defeito</li>
<li><strong>Garantia</strong>: Produtos devem funcionar como anunciado</li>
<li><strong>Informação clara</strong>: Preços e condições devem ser transparentes</li>
<li><strong>Proteção contra práticas enganosas</strong></li>
<li><strong>Direito de cancelamento</strong>: Compras online podem ser canceladas em 14 dias</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Compras online</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Para compras online, você tem direito a <strong>cancelar</strong> dentro de 14 dias (cooling-off period). O vendedor deve reembolsar em até 14 dias após o cancelamento.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde buscar ajuda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Se você tem problemas com um produto ou serviço:</p><!-- /wp:list -->
<!-- wp:list --><ol>
<li>Contate o <strong>vendedor</strong> primeiro</li>
<li>Se não resolver, contate o <strong>CCPC</strong></li>
<li>Para serviços financeiros, contate o <strong>FSPO (Financial Services and Pensions Ombudsman)</strong></li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>CCPC:</strong> <a href="https://www.ccpc.ie/" target="_blank" rel="noopener noreferrer">ccpc.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Guarde <strong>recibos</strong> de todas as compras</li>
<li>Leia os <strong>termos e condições</strong> antes de comprar</li>
<li>Desconfie de <strong>ofertas muito boas</strong> para ser verdade</li>
<li>O CCPC tem <strong>informações gratuitas</strong> sobre seus direitos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Posso devolver um produto comprado na Irlanda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, se o produto tem defeito ou não corresponde à descrição. Para compras online, você tem 14 dias para cancelar.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que é o CCPC?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É a comissão de proteção ao consumidor da Irlanda. Fornece informações e ajuda com reclamações.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.ccpc.ie/" target="_blank" rel="noopener noreferrer">CCPC - Competition and Consumer Protection Commission</a></li>
<li><a href="https://www.fspo.ie/" target="_blank" rel="noopener noreferrer">FSPO - Financial Services and Pensions Ombudsman</a></li>
<li><a href="https://www.citizensinformation.ie/en/consumer/" target="_blank" rel="noopener noreferrer">Citizens Information - Consumer</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Direitos do Consumidor na Irlanda: Guia Completo',
	'direitos-consumidor',
	$consumer_content,
	'Guia sobre direitos do consumidor na Irlanda. Devoluções, garantias, compras online e informações verificadas em fontes oficiais.',
	array( 'financas' )
);

// 28. Data Protection
$dataprotection_content = <<<'HTML'
<!-- wp:heading --><h2>O que é a proteção de dados na Irlanda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A proteção de dados na Irlanda é regulada pelo <strong>Data Protection Commission (DPC)</strong>. A Irlanda segue o <strong>GDPR (General Data Protection Regulation)</strong> da União Europeia, que dá aos cidadãos fortes direitos sobre seus dados pessoais.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem é protegido?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os residentes na Irlanda têm direitos de proteção de dados sob o GDPR. Isso inclui brasileiros que vivem na Irlanda.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Seus direitos sob o GDPR</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>Direito de acesso</strong>: Ver quais dados uma empresa tem sobre você</li>
<li><strong>Direito de retificação</strong>: Corrigir dados incorretos</li>
<li><strong>Direito de apagamento</strong>: Solicitar a exclusão dos seus dados</li>
<li><strong>Direito de portabilidade</strong>: Transferir seus dados para outra empresa</li>
<li><strong>Direito de oposição</strong>: Opor-se ao processamento dos seus dados</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como exercer seus direitos</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Contate a <strong>empresa</strong> que tem seus dados</li>
<li>Faça a solicitação por escrito</li>
<li>A empresa deve responder em <strong>1 mês</strong></li>
<li>Se não resolver, contate o <strong>DPC</strong></li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>DPC:</strong> <a href="https://www.dataprotection.ie/" target="_blank" rel="noopener noreferrer">dataprotection.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O GDPR se aplica a <strong>todas</strong> as empresas que processam seus dados na UE</li>
<li>Empresas devem obter seu <strong>consentimento</strong> para processar seus dados</li>
<li>Você pode solicitar <strong>cópias</strong> dos seus dados a qualquer momento</li>
<li>O DPC pode <strong>multar</strong> empresas que violam o GDPR</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>O que é o GDPR?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É o Regulamento Geral de Proteção de Dados da União Europeia. Dá aos cidadãos fortes direitos sobre seus dados pessoais.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Como faço uma reclamação de proteção de dados?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Primeiro contate a empresa. Se não resolver, contate o Data Protection Commission (DPC).</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.dataprotection.ie/" target="_blank" rel="noopener noreferrer">Data Protection Commission</a></li>
<li><a href="https://www.citizensinformation.ie/en/government-in-ireland/data-protection/" target="_blank" rel="noopener noreferrer">Citizens Information - Data Protection</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Proteção de Dados na Irlanda: Seus Direitos (GDPR)',
	'protecao-dados',
	$dataprotection_content,
	'Guia sobre proteção de dados na Irlanda. Seus direitos sob o GDPR, como exercê-los e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// 29. Garda & Police
$garda_content = <<<'HTML'
<!-- wp:heading --><h2>O que é a Garda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>A <strong>Garda Síochána</strong> (pronuncia-se "Garda Siokana") é a polícia nacional da Irlanda. É responsável pela segurança pública, prevenção de crimes e aplicação da lei.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa conhecer a Garda?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os residentes e visitantes na Irlanda. É importante saber como contatar a Garda em emergências e como interagir com a polícia.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Números de emergência</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li><strong>112</strong> ou <strong>999</strong>: Emergências (polícia, ambulância, bombeiros)</li>
<li><strong>Garda Confidential Line</strong>: 1800 666 111 (para denúncias anônimas)</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como interagir com a Garda</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Em emergências, ligue <strong>112</strong> ou <strong>999</strong></li>
<li>Para assuntos não urgentes, visite a <strong>estação da Garda</strong> local</li>
<li>Se parado pela Garda, <strong>coopere</strong> e apresente seus documentos</li>
<li>Você tem direito a <strong>um intérprete</strong> se não fala inglês</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Registro de imigração</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Fora de Dublin, o registro de imigração (IRP) é feito na <strong>Garda National Immigration Bureau (GNIB)</strong>. Em Dublin, é feito no <strong>Immigration Office</strong>.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Garda Síochána:</strong> <a href="https://www.garda.ie/en/" target="_blank" rel="noopener noreferrer">garda.ie</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>A Garda é <strong>acessível</strong> e geralmente ajuda com informações</li>
<li>Você pode pedir um <strong>número de referência</strong> ao reportar um crime</li>
<li>Se você é vítima de crime, a Garda pode fornecer <strong>apoio</strong></li>
<li>Nunca ofereça <strong>suborno</strong> à Garda - é crime grave</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>O que é a Garda?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>É a polícia nacional da Irlanda. O nome completo é Garda Síochána, que significa "Guardiões da Paz" em irlandês.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Qual número ligar em emergência?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ligue 112 ou 999. Ambos são gratuitos e funcionam 24 horas.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.garda.ie/en/" target="_blank" rel="noopener noreferrer">Garda Síochána</a></li>
<li><a href="https://www.citizensinformation.ie/en/justice/" target="_blank" rel="noopener noreferrer">Citizens Information - Justice</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Garda na Irlanda: Polícia e Emergências',
	'garda',
	$garda_content,
	'Guia sobre a Garda (polícia) na Irlanda. Números de emergência, como interagir e informações verificadas em fontes oficiais.',
	array( 'documentos' )
);

// 30. Revenue MyAccount
$revenue_content = <<<'HTML'
<!-- wp:heading --><h2>O que é o Revenue MyAccount?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O <strong>Revenue MyAccount</strong> é o portal online do <strong>Revenue (Office of the Revenue Commissioners)</strong> para contribuintes individuais. É onde você gerencia seus impostos, verifica créditos fiscais, solicita reembolsos e acessa informações fiscais.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Quem precisa usar?</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todos os trabalhadores na Irlanda. Se você é empregado (PAYE), o Revenue MyAccount é essencial para:</p><!-- /wp:list -->
<!-- wp:list --><ul>
<li>Verificar seus <strong>créditos fiscais</strong></li>
<li>Solicitar <strong>reembolso</strong> de impostos</li>
<li>Registrar <strong>novos empregos</strong></li>
<li>Atualizar seus <strong>dados pessoais</strong></li>
<li>Acessar seus <strong>comprovantes de renda</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Como criar uma conta</h2><!-- /wp:heading -->
<!-- wp:list --><ol>
<li>Acesse o site do <strong>Revenue</strong></li>
<li>Clique em "MyAccount"</li>
<li>Registre-se com seu <strong>PPS Number</strong></li>
<li>Verifique sua identidade</li>
<li>Crie sua senha</li>
</ol><!-- /wp:list -->

<!-- wp:heading --><h2>O que você pode fazer</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>Verificar e atualizar <strong>créditos fiscais</strong></li>
<li>Solicitar <strong>reembolso</strong> de impostos</li>
<li>Registrar <strong>novos empregos</strong></li>
<li>Declarar <strong>renda adicional</strong></li>
<li>Acessar <strong>comprovantes</strong> de renda e impostos</li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Onde acessar</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>Acessar Revenue MyAccount:</strong> <a href="https://www.revenue.ie/" target="_blank" rel="noopener noreferrer">Revenue - MyAccount</a></p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Importante saber</h2><!-- /wp:heading -->
<!-- wp:list --><ul>
<li>O Revenue MyAccount é <strong>gratuito</strong></li>
<li>Você precisa de <strong>PPS Number</strong> para criar conta</li>
<li>Verifique seus créditos fiscais <strong>regularmente</strong></li>
<li>Se pagou imposto a mais, solicite <strong>reembolso</strong></li>
</ul><!-- /wp:list -->

<!-- wp:heading --><h2>Perguntas frequentes</h2><!-- /wp:heading -->
<!-- wp:heading --><h3>Preciso de PPS Number para o Revenue?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Sim, o PPS Number é sua identificação fiscal na Irlanda e é necessário para criar conta no Revenue.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Como solicito reembolso de impostos?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Acesse o Revenue MyAccount, verifique seus créditos fiscais e solicite o reembolso. O Revenue processa e deposita na sua conta bancária.</p><!-- /wp:paragraph -->

<!-- wp:heading --><h2>Fontes oficiais</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este guia é apenas informativo. Regras e procedimentos podem mudar. Consulte sempre a fonte oficial antes de tomar uma decisão.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><strong>Última verificação: 17 de agosto de 2026</strong></p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="https://www.revenue.ie/" target="_blank" rel="noopener noreferrer">Revenue - Office of the Revenue Commissioners</a></li>
<li><a href="https://www.citizensinformation.ie/en/money-and-tax/tax/" target="_blank" rel="noopener noreferrer">Citizens Information - Tax</a></li>
</ul><!-- /wp:list -->
HTML;

conexao_create_guide(
	'Revenue MyAccount na Irlanda: Como Usar',
	'revenue-myaccount',
	$revenue_content,
	'Guia sobre o Revenue MyAccount na Irlanda. Como criar conta, verificar créditos fiscais, solicitar reembolso e informações verificadas em fontes oficiais.',
	array( 'financas' )
);

WP_CLI::success( 'All guides updated successfully.' );