<?php
/**
 * Content Type Configuration for the Conexão Admin UX.
 *
 * This is the single source of truth for how each custom content type is
 * presented in wp-admin. Every content type declares:
 *   - labels (list page, add button, editor heading)
 *   - sections for the editor (cards / collapsible panels)
 *   - fields with the correct UI control (date, time, select, media…)
 *   - list columns
 *   - statuses + badges
 *   - bulk actions
 *   - dashboard summaries (actionable)
 *   - default sort / date helper
 *
 * The same UX components (`Conexao_Admin_Ux_List`, `Conexao_Admin_Ux_Editor`,
 * `Conexao_Admin_Ux_Fields`, `Conexao_Admin_Ux_Actions`) are reused for every
 * content type — only this config changes per type.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || exit;

final class Conexao_Admin_Ux_Config {

	/**
	 * The list of post types that use the redesigned admin UX.
	 *
	 * @var string[]
	 */
	const SUPPORTED_TYPES = array( 'event', 'guide', 'job', 'sponsor', 'course_provider', 'leisure' );

	/**
	 * Get the full configuration for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array|null Config array, or null when unsupported.
	 */
	public static function get( $post_type ) {
		$configs = self::all();
		return isset( $configs[ $post_type ] ) ? $configs[ $post_type ] : null;
	}

	/**
	 * Get the configs for all supported post types.
	 *
	 * @return array
	 */
	public static function all() {
		static $configs = null;

		if ( null === $configs ) {
			$configs = array(
				'event'           => self::event_config(),
				'guide'           => self::guide_config(),
				'job'             => self::job_config(),
				'sponsor'         => self::sponsor_config(),
				'course_provider' => self::course_provider_config(),
				'leisure'         => self::leisure_config(),
			);
		}

		return $configs;
	}

	/**
	 * List of Irish counties for the location dropdowns.
	 *
	 * @return string[]
	 */
	public static function counties() {
		return array(
			'Carlow',
			'Cavan',
			'Clare',
			'Cork',
			'Donegal',
			'Dublin',
			'Galway',
			'Kerry',
			'Kildare',
			'Kilkenny',
			'Laois',
			'Leitrim',
			'Limerick',
			'Longford',
			'Louth',
			'Mayo',
			'Meath',
			'Monaghan',
			'Offaly',
			'Roscommon',
			'Sligo',
			'Tipperary',
			'Waterford',
			'Westmeath',
			'Wexford',
			'Wicklow',
		);
	}

	/**
	 * Known Laois towns (matching the importer) for the dependent Town/City
	 * dropdown when County = Laois. Other counties offer a searchable text
	 * input with autocomplete suggestions from the conexao_town taxonomy.
	 *
	 * @return string[]
	 */
	public static function laois_towns() {
		return array(
			'Abbeyleix',
			'Ballacolla',
			'Ballaghmore',
			'Ballickmoyler',
			'Ballinakill',
			'Ballybrittas',
			'Ballyfin',
			'Ballylinan',
			'Ballyroan',
			'Borris-in-Ossory',
			'Camps',
			'Castletown',
			'Clonaslee',
			'Coolrain',
			'Cullohill',
			'Donaghmore',
			'Durrow',
			'Em',
			'Errihill',
			'Jamestown',
			'Killenard',
			'Killeshin',
			'Mountmellick',
			'Mountrath',
			'New Inn',
			'Newtown',
			'Portarlington',
			'Portlaoise',
			'Rathdowney',
			'Rosenallis',
			'Shannon',
			'Stradbally',
			'Timahoe',
			'Vicarstown',
		);
	}

	/**
	 * Employment types for Jobs.
	 *
	 * @return string[]
	 */
	public static function employment_types() {
		return array(
			'Tempo integral',
			'Meio período',
			'Contrato',
			'Temporário',
			'Estágio',
			'Autônomo / Freelancer',
			'Remoto',
		);
	}

	/**
	 * Categories used by the Apoiadores directory.
	 *
	 * @return string[]
	 */
	public static function sponsor_categories() {
		return array(
			'Serviços Profissionais',
			'Saúde e Bem-estar',
			'Marketing e Negócios',
			'Artes e craft',
			'Alimentação',
			'Informações',
			'Educação',
			'ONG / Sem fins lucrativos',
		);
	}

	/**
	 * Types of supporters (apoiadores).
	 *
	 * @return string[]
	 */
	public static function sponsor_types() {
		return array(
			'Empresa',
			'ONG / Associação',
			'Instituição',
			'Profissional liberal',
			'Grupo comunitário',
		);
	}

	/**
	 * Categories used by the Cursos (course provider) directory.
	 *
	 * @return string[]
	 */
	public static function provider_categories() {
		return array(
			'Educação',
			'Formação Profissional',
			'Cursos Online',
			'Negócios',
			'Diretórios de Cursos',
		);
	}

	/**
	 * Leisure / tourism categories for the /lazer/ directory.
	 * Matches the seeded `conexao_category` terms.
	 *
	 * @return string[]
	 */
	public static function leisure_categories() {
		return array(
			'Natureza',
			'História',
			'Cultura',
			'Família',
			'Praias',
			'Caminhadas',
			'Aventura',
			'Jardins',
			'Museus',
			'Castelos',
			'Vida Selvagem',
			'Patrimônio',
			'Cidades',
			'Ilhas',
			'Greenways',
			'Outros',
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * LAZER & TURISMO
	 * ------------------------------------------------------------------
	 */
	private static function leisure_config() {
		return array(
			'post_type'  => 'leisure',
			'labels'     => array(
				'singular'        => 'Local de Lazer',
				'plural'          => 'Lazer e Turismo',
				'add_button'      => 'Adicionar Local',
				'add_new_item'    => 'Adicionar Local',
				'edit_item'       => 'Editar Local',
				'empty_title'     => 'Ainda não existem locais',
				'empty_message'   => 'Adicione o primeiro lugar ou atividade turística à diretoria de Lazer.',
				'success_saved'   => 'Local atualizado com sucesso.',
				'success_created' => 'Local criado com sucesso.',
				'success_published' => 'Local publicado com sucesso.',
				'success_draft'   => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Local duplicado como rascunho.',
				'success_archived' => 'Local arquivado com sucesso.',
				'success_bulk'    => 'Locais atualizados com sucesso.',
			),
			'date_meta'  => '_leisure_created_date',
			'sections'   => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-palmtree',
					'priority' => 10,
					'fields'   => array(
						array( 'key' => '_leisure_name', 'label' => 'Nome do local', 'type' => 'text', 'required' => true, 'help' => 'Ex.: "Cliffs of Moher", "Glendalough".' ),
						array( 'key' => '_leisure_short_description', 'label' => 'Descrição curta', 'type' => 'text', 'help' => 'Resumo exibido nos cartões da página /lazer/.' ),
						array( 'key' => '_leisure_description', 'label' => 'Descrição completa', 'type' => 'editor', 'help' => 'Descreva o local, o que ver/fazer e por que vale a pena visitar.' ),
						array( 'key' => '_leisure_image_attachment_id', 'label' => 'Imagem em destaque', 'type' => 'media', 'help' => 'Imagem principal do local com direitos adequados (armazenada na biblioteca de mídia). Também disponível na secção "Imagem e fonte".' ),
					),
				),
				'classificacao' => array(
					'title'    => 'Classificação',
					'icon'     => 'dashicons-category',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_leisure_category', 'label' => 'Categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category', 'help' => 'Tipo de atração: Natureza, História, Praias, Castelos, etc.' ),
						array( 'key' => '_leisure_county', 'label' => 'County', 'type' => 'select', 'options' => self::counties(), 'placeholder' => 'Selecione o county', 'help' => 'Condado onde o local está situado.' ),
						array( 'key' => '_leisure_town', 'label' => 'Cidade / Vila', 'type' => 'town', 'help' => 'Cidade ou vila mais próxima.' ),
					),
				),
				'localizacao' => array(
					'title'    => 'Localização e contato',
					'icon'     => 'dashicons-location-alt',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_leisure_address', 'label' => 'Endereço', 'type' => 'text', 'help' => 'Rua, número e código postal, se disponível.' ),
						array( 'key' => '_leisure_official_website', 'label' => 'Official Website URL', 'type' => 'url', 'help' => 'Endereço do site oficial do local (ex.: https://www.example.com/). Ao clicar no local, o visitante será direcionado diretamente para este link.' ),
						array( 'key' => '_leisure_discover_ireland', 'label' => 'Discover Ireland URL', 'type' => 'url', 'help' => 'Página deste local no Discover Ireland (ex.: https://www.discoverireland.ie/...). Usado apenas como referência quando não houver site oficial.' ),
						array( 'key' => '_leisure_website', 'label' => 'Site oficial (legado)', 'type' => 'url', 'advanced' => true, 'help' => 'Campo antigo usado anteriormente. Prefira preencher "Official Website URL" acima.' ),
						array( 'key' => '_leisure_map_url', 'label' => 'Link do mapa', 'type' => 'url', 'help' => 'Link do Google Maps / localização.' ),
					),
				),
				'imagem'  => array(
					'title'    => 'Imagem e fonte',
					'icon'     => 'dashicons-format-image',
					'priority' => 25,
					'fields'   => array(
						array( 'key' => '_leisure_image_attachment_id', 'label' => 'Imagem em destaque', 'type' => 'media', 'help' => 'Imagem principal do local com direitos adequados (armazenada na biblioteca de mídia). Também disponível na secção "Imagem e fonte".' ),
						array( 'key' => '_leisure_image_source', 'label' => 'Fonte da imagem', 'type' => 'text', 'help' => 'Ex.: "Biblioteca de mídia", "Site oficial", "Wikimedia Commons", "Enviada".' ),
						array( 'key' => '_leisure_image_source_url', 'label' => 'URL da página de origem', 'type' => 'url', 'help' => 'Página onde a imagem/mais informações estão (ex.: página do arquivo no Wikimedia Commons). Serve como referência e atribuição.' ),
						array( 'key' => '_leisure_image_author', 'label' => 'Fotógrafo / autor', 'type' => 'text', 'help' => 'Nome do autor da imagem, conforme indicado na página do Wikimedia Commons. Usado para atribuição.' ),
						array( 'key' => '_leisure_image_license', 'label' => 'Licença', 'type' => 'text', 'help' => 'Licença da imagem (ex.: "CC BY-SA 4.0", "Public Domain", "CC0").' ),
						array( 'key' => '_leisure_image_attribution', 'label' => 'Atribuição / crédito', 'type' => 'textarea', 'help' => 'Texto de atribuição completo (fotógrafo + licença + fonte). Ex.: "Foto: John Smith, CC BY-SA 4.0, Wikimedia Commons".' ),
						array( 'key' => '_leisure_image_alt_text', 'label' => 'Texto alternativo (alt)', 'type' => 'text', 'help' => 'Texto descritivo para acessibilidade (ex.: "Cliffs of Moher, County Clare"). Se vazio, usa o nome do local.' ),
						array( 'key' => '_leisure_image_status', 'label' => 'Status da imagem', 'type' => 'select', 'options' => array( 'none' => 'Nenhuma', 'pending' => 'Imagem pendente', 'local' => 'Local (mídia)' ), 'help' => 'Estado atual da imagem. Use "Imagem pendente" quando ainda não houver uma imagem com direitos adequados.' ),
					),
				),
				'atributos' => array(
					'title'    => 'Atributos úteis',
					'icon'     => 'dashicons-info-outline',
					'priority' => 40,
					'fields'   => array(
						array( 'key' => '_leisure_free', 'label' => 'Custo', 'type' => 'text', 'help' => 'Ex.: "Grátis", "Pago", "€8 por adulto". Deixe em branco se não tiver certeza.' ),
						array( 'key' => '_leisure_family', 'label' => 'Adequado para famílias', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_accessibility', 'label' => 'Acessível', 'type' => 'checkbox', 'help' => 'Acessibilidade para pessoas com mobilidade reduzida.' ),
						array( 'key' => '_leisure_pet_friendly', 'label' => 'Pet friendly', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_indoor', 'label' => 'Interior', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_outdoor', 'label' => 'Exterior', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_parking', 'label' => 'Estacionamento', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_booking', 'label' => 'Necessita reserva', 'type' => 'checkbox' ),
						array( 'key' => '_leisure_duration', 'label' => 'Duração recomendada', 'type' => 'text', 'help' => 'Ex.: "2-3 horas", "meio dia".' ),
						array( 'key' => '_leisure_best_time', 'label' => 'Melhor época para visitar', 'type' => 'text', 'help' => 'Ex.: "Primavera", "todo o ano".' ),
					),
				),
				'destaque' => array(
					'title'    => 'Destaque',
					'icon'     => 'dashicons-star-filled',
					'priority' => 50,
					'fields'   => array(
						array( 'key' => '_leisure_feature', 'label' => 'Local em destaque', 'type' => 'checkbox', 'help' => 'Exibir na seção "Destinos em destaque" da /lazer/.' ),
					),
				),
			),
			'publishing' => array(
				'statuses' => array(
					'draft'     => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'needs_review' => array( 'label' => 'Revisão', 'badge' => 'review' ),
					'published' => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'archived'  => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				'status_meta' => '_leisure_status',
				'default_status' => 'draft',
			),
			'columns'    => array(
				'category' => array( 'label' => 'Categoria', 'render' => 'category' ),
				'county'   => array( 'label' => 'County', 'render' => 'county' ),
				'featured' => array( 'label' => 'Destaque', 'meta' => '_leisure_feature', 'format' => 'featured' ),
				'status'   => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions' => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'category' => array( 'label' => 'Atribuir categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category' ),
				'county'   => array( 'label' => 'Atribuir localização (County)', 'type' => 'taxonomy', 'taxonomy' => 'conexao_county' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente os locais selecionados? Esta ação não pode ser desfeita.' ),
			),
			'summary'    => array(
				array( 'key' => 'published', 'label' => 'Publicados', 'status' => 'published' ),
				array( 'key' => 'review', 'label' => 'Revisão', 'status' => 'needs_review' ),
			),
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * EVENTOS
	 * ------------------------------------------------------------------
	 */
	private static function event_config() {
		return array(
			'post_type'          => 'event',
			'labels'             => array(
				'singular'       => 'Evento',
				'plural'         => 'Eventos',
				'add_button'     => 'Adicionar Evento',
				'add_new_item'   => 'Adicionar Evento',
				'edit_item'      => 'Editar Evento',
				'empty_title'    => 'Ainda não existem eventos',
				'empty_message'  => 'Crie o primeiro evento para começar a preencher a agenda da comunidade.',
				'success_saved'  => 'Evento atualizado com sucesso.',
				'success_created' => 'Evento criado com sucesso.',
				'success_published' => 'Evento publicado com sucesso.',
				'success_draft'  => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Evento duplicado como rascunho.',
				'success_archived' => 'Evento arquivado com sucesso.',
				'success_bulk'   => 'Eventos atualizados com sucesso.',
			),
			// Date helper is used for the public date column.
			'date_meta'          => '_event_date',
			// Editor sections. Each section becomes a card with a heading.
			'sections'           => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-megaphone',
					'priority' => 10,
					'fields'   => array(
						array(
							'key'     => '_event_title',
							'label'   => 'Título do evento',
							'type'    => 'text',
							'required' => true,
							'help'    => 'Dê um nome claro e curto. Ex.: "Café da Manhã para Novos Imigrantes".',
						),
						array(
							'key'     => '_event_description',
							'label'   => 'Descrição',
							'type'    => 'editor',
							'help'    => 'Descreva o evento: programação, público-alvo e o que os participantes devem saber.',
						),
						array(
							'key'     => '_event_banner',
							'label'   => 'Banner / Imagem',
							'type'    => 'media',
							'help'    => 'Imagem em destaque do evento na página pública.',
						),
					),
				),
				'datetime'  => array(
					'title'    => 'Data e horário',
					'icon'     => 'dashicons-calendar-alt',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_event_date', 'label' => 'Data de início', 'type' => 'date', 'required' => true, 'help' => 'O primeiro dia do evento.' ),
						array( 'key' => '_event_start_time', 'label' => 'Hora de início', 'type' => 'time' ),
						array( 'key' => '_event_end_date', 'label' => 'Data de término', 'type' => 'date', 'help' => 'Deixe em branco para eventos de um dia.' ),
						array( 'key' => '_event_end_time', 'label' => 'Hora de término', 'type' => 'time' ),
					),
				),
				'location'  => array(
					'title'    => 'Localização',
					'icon'     => 'dashicons-location-alt',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_event_county', 'label' => 'County', 'type' => 'select', 'options' => self::counties(), 'placeholder' => 'Selecione o county', 'help' => 'Usado para organizar eventos nos filtros públicos.' ),
						array( 'key' => '_event_town', 'label' => 'Town/City', 'type' => 'town', 'help' => 'Usado para organizar eventos nos filtros públicos de localização.' ),
						array( 'key' => '_event_venue', 'label' => 'Venue', 'type' => 'text', 'help' => 'Nome do local — ex.: "Portlaoise Community Centre".' ),
						array( 'key' => '_event_address', 'label' => 'Endereço', 'type' => 'text', 'help' => 'Rua, número e código postal, se disponível.' ),
					),
				),
				'details'   => array(
					'title'    => 'Informações do evento',
					'icon'     => 'dashicons-info-outline',
					'priority' => 40,
					'fields'   => array(
						array( 'key' => '_event_category', 'label' => 'Categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category', 'help' => 'Selecione a categoria temática do evento.' ),
						array( 'key' => '_event_organizer', 'label' => 'Organizador', 'type' => 'text', 'help' => 'Quem organiza o evento — ex.: "Laois Tourism".' ),
						array( 'key' => '_event_price', 'label' => 'Preço', 'type' => 'currency', 'help' => 'Preço do ingresso. Ex.: 15.00 ou "Grátis".' ),
						array( 'key' => '_event_registration', 'label' => 'Informações de inscrição', 'type' => 'textarea', 'help' => 'Como as pessoas se inscrevem ou reservam um lugar.' ),
					),
				),
				'source'    => array(
					'title'    => 'Fonte',
					'icon'     => 'dashicons-external',
					'priority' => 50,
					'fields'   => array(
						array( 'key' => '_event_source', 'label' => 'Fonte', 'type' => 'text', 'help' => 'O site ou organização onde este evento foi descoberto.' ),
						array( 'key' => '_event_url', 'label' => 'URL original', 'type' => 'url', 'help' => 'A página original onde os visitantes podem ver mais informações ou se inscrever.' ),
						array( 'key' => '_event_source_id', 'label' => 'ID externo do evento', 'type' => 'text', 'advanced' => true, 'help' => 'Identificador interno usado para evitar duplicatas ao importar. Não altere a menos que saiba o que está fazendo.' ),
						array( 'key' => '_event_import_status', 'label' => 'Status da importação', 'type' => 'readonly', 'help' => 'Estado atual da importação automática.' ),
					),
				),
			),
			'publishing'         => array(
				'statuses' => array(
					'draft'            => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'published'        => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'source_not_found' => array( 'label' => 'Indisponível na fonte', 'badge' => 'warning' ),
					'expired'          => array( 'label' => 'Expirado', 'badge' => 'expired' ),
					'rejected'         => array( 'label' => 'Rejeitado', 'badge' => 'danger' ),
					'archived'         => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				// The meta key used for the status (defaults to the post_status for
				// regular content types, but events use their custom importer status).
				'status_meta' => '_event_status',
				'default_status' => 'draft',
			),
			'columns'            => array(
				'date'       => array( 'label' => 'Data', 'meta' => '_event_date', 'format' => 'date' ),
				'location'   => array( 'label' => 'Localização', 'render' => 'event_location' ),
				'source'     => array( 'label' => 'Fonte', 'render' => 'source' ),
				'status'     => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions'       => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'category' => array( 'label' => 'Atribuir categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category' ),
				'county'   => array( 'label' => 'Atribuir localização (County)', 'type' => 'taxonomy', 'taxonomy' => 'conexao_county' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente os eventos selecionados? Esta ação não pode ser desfeita.' ),
			),
			'summary'            => array(
				array( 'key' => 'published', 'label' => 'Publicados', 'status' => 'published' ),
				array( 'key' => 'week', 'label' => 'Esta semana', 'date_range' => 'week' ),
			),
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * GUIAS
	 * ------------------------------------------------------------------
	 */
	private static function guide_config() {
		return array(
			'post_type'  => 'guide',
			'labels'     => array(
				'singular'        => 'Guia',
				'plural'          => 'Guias',
				'add_button'      => 'Adicionar Guia',
				'add_new_item'    => 'Adicionar Guia',
				'edit_item'       => 'Editar Guia',
				'empty_title'     => 'Ainda não existem guias',
				'empty_message'   => 'Crie o primeiro guia para ajudar a comunidade com informações práticas.',
				'success_saved'   => 'Guia atualizado com sucesso.',
				'success_created' => 'Guia criado com sucesso.',
				'success_published' => 'Guia publicado com sucesso.',
				'success_draft'   => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Guia duplicado como rascunho.',
				'success_archived' => 'Guia arquivado com sucesso.',
				'success_bulk'    => 'Guias atualizados com sucesso.',
			),
			'date_meta'  => '_guide_date',
			'sections'   => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-book-alt',
					'priority' => 10,
					'fields'   => array(
						array( 'key' => '_guide_title', 'label' => 'Título do guia', 'type' => 'text', 'required' => true, 'help' => 'Ex.: "Guia para quem está com problemas financeiros na Irlanda".' ),
						array( 'key' => '_guide_content', 'label' => 'Conteúdo', 'type' => 'editor', 'help' => 'Descreva as orientações, recursos e links úteis do guia.' ),
						array( 'key' => '_guide_featured_image', 'label' => 'Imagem em destaque', 'type' => 'media' ),
					),
				),
				'classificacao' => array(
					'title'    => 'Classificação',
					'icon'     => 'dashicons-category',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_guide_category', 'label' => 'Categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category' ),
						array( 'key' => '_guide_county', 'label' => 'Localização (County)', 'type' => 'select', 'options' => self::counties(), 'placeholder' => 'Selecione o county' ),
						array( 'key' => '_guide_town', 'label' => 'Town/City', 'type' => 'town' ),
					),
				),
				'relacionados' => array(
					'title'    => 'Informações relacionadas',
					'icon'     => 'dashicons-admin-links',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_guide_useful_links', 'label' => 'Links úteis', 'type' => 'textarea', 'help' => 'Inclua links (um por linha) para serviços, órgãos públicos e recursos relevantes.' ),
						array( 'key' => '_guide_source', 'label' => 'Fonte', 'type' => 'text', 'help' => 'O site ou organização de onde este guia foi obtido.' ),
						array( 'key' => '_guide_url', 'label' => 'URL original', 'type' => 'url' ),
					),
				),
			),
			'publishing' => array(
				'statuses' => array(
					'draft'     => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'needs_review' => array( 'label' => 'Revisão', 'badge' => 'review' ),
					'published' => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'archived'  => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				'status_meta' => '_guide_status',
				'default_status' => 'draft',
			),
			'columns'    => array(
				'category' => array( 'label' => 'Categoria', 'render' => 'category' ),
				'date'     => array( 'label' => 'Data', 'meta' => '_guide_date', 'format' => 'date' ),
				'status'   => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions' => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'category' => array( 'label' => 'Atribuir categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente os guias selecionados? Esta ação não pode ser desfeita.' ),
			),
			'summary'    => array(
				array( 'key' => 'published', 'label' => 'Publicados', 'status' => 'published' ),
				array( 'key' => 'review', 'label' => 'Revisão', 'status' => 'needs_review' ),
			),
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * EMPREGOS
	 * ------------------------------------------------------------------
	 */
	private static function job_config() {
		return array(
			'post_type'  => 'job',
			'labels'     => array(
				'singular'        => 'Vaga',
				'plural'          => 'Empregos',
				'add_button'      => 'Adicionar Vaga',
				'add_new_item'    => 'Adicionar Vaga',
				'edit_item'       => 'Editar Vaga',
				'empty_title'     => 'Ainda não existem vagas',
				'empty_message'   => 'Publique a primeira vaga para conectar empregadores à comunidade.',
				'success_saved'   => 'Vaga atualizada com sucesso.',
				'success_created' => 'Vaga criada com sucesso.',
				'success_published' => 'Vaga publicada com sucesso.',
				'success_draft'   => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Vaga duplicada como rascunho.',
				'success_archived' => 'Vaga arquivada com sucesso.',
				'success_bulk'    => 'Vagas atualizadas com sucesso.',
			),
			'date_meta'  => '_job_closing_date',
			'sections'   => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-portfolio',
					'priority' => 10,
					'fields'   => array(
						array( 'key' => '_job_title', 'label' => 'Título da vaga', 'type' => 'text', 'required' => true, 'help' => 'Ex.: "Enfermeiro(a) registado(a) — Dublin".' ),
						array( 'key' => '_job_company', 'label' => 'Empresa', 'type' => 'text', 'help' => 'Nome da empresa contratante.' ),
						array( 'key' => '_job_description', 'label' => 'Descrição da vaga', 'type' => 'editor', 'help' => 'Descreva as responsabilidades e o contexto da vaga.' ),
						array( 'key' => '_job_requirements', 'label' => 'Requisitos', 'type' => 'textarea', 'help' => 'Liste os requisitos essenciais e desejáveis da vaga.' ),
					),
				),
				'detalhes'  => array(
					'title'    => 'Detalhes da vaga',
					'icon'     => 'dashicons-clipboard',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_job_location', 'label' => 'Localização', 'type' => 'text', 'help' => 'Cidade / county da vaga. Ex.: "Dublin" ou "Portlaoise, Laois".' ),
						array( 'key' => '_job_salary', 'label' => 'Salário', 'type' => 'currency', 'help' => 'Faixa salarial. Ex.: "€35.000 – €42.000/ano".' ),
						array( 'key' => '_job_employment_type', 'label' => 'Tipo de contrato', 'type' => 'select', 'options' => self::employment_types(), 'placeholder' => 'Selecione o tipo' ),
						array( 'key' => '_job_closing_date', 'label' => 'Data de encerramento', 'type' => 'date', 'help' => 'Data limite para candidatura.' ),
					),
				),
				'candidatura' => array(
					'title'    => 'Candidatura',
					'icon'     => 'dashicons-email-alt',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_job_application_url', 'label' => 'URL de candidatura', 'type' => 'url', 'help' => 'Link para a página onde os candidatos devem se inscrever.' ),
						array( 'key' => '_job_source', 'label' => 'Fonte', 'type' => 'text', 'help' => 'O site ou organização onde esta vaga foi encontrada.' ),
					),
				),
			),
			'publishing' => array(
				'statuses' => array(
					'draft'     => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'needs_review' => array( 'label' => 'Revisão', 'badge' => 'review' ),
					'published' => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'archived'  => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				'status_meta' => '_job_status',
				'default_status' => 'draft',
			),
			'columns'    => array(
				'company'  => array( 'label' => 'Empresa', 'meta' => '_job_company' ),
				'location' => array( 'label' => 'Localização', 'meta' => '_job_location' ),
				'closing'  => array( 'label' => 'Encerramento', 'meta' => '_job_closing_date', 'format' => 'date' ),
				'status'   => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions' => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente as vagas selecionadas? Esta ação não pode ser desfeita.' ),
			),
			'summary'    => array(
				array( 'key' => 'published', 'label' => 'Publicadas', 'status' => 'published' ),
				array( 'key' => 'week', 'label' => 'Novas esta semana', 'date_range' => 'week' ),
				array( 'key' => 'closing_soon', 'label' => 'Encerram esta semana', 'closing_range' => 'week' ),
			),
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * APOIADORES
	 * ------------------------------------------------------------------
	 */
	private static function sponsor_config() {
		return array(
			'post_type'  => 'sponsor',
			'labels'     => array(
				'singular'        => 'Apoiador',
				'plural'          => 'Apoiadores',
				'add_button'      => 'Adicionar Apoiador',
				'add_new_item'    => 'Adicionar Apoiador',
				'edit_item'       => 'Editar Apoiador',
				'empty_title'     => 'Ainda não existem apoiadores',
				'empty_message'   => 'Adicione o primeiro apoiador à comunidade.',
				'success_saved'   => 'Apoiador atualizado com sucesso.',
				'success_created' => 'Apoiador criado com sucesso.',
				'success_published' => 'Apoiador publicado com sucesso.',
				'success_draft'   => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Apoiador duplicado como rascunho.',
				'success_archived' => 'Apoiador arquivado com sucesso.',
				'success_bulk'    => 'Apoiadores atualizados com sucesso.',
			),
			'date_meta'  => '_sponsor_created_date',
			'sections'   => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-heart',
					'priority' => 10,
					'fields'   => array(
						array( 'key' => '_sponsor_name', 'label' => 'Nome do apoiador', 'type' => 'text', 'required' => true, 'help' => 'Nome da organização, empresa ou entidade.' ),
						array( 'key' => '_sponsor_category', 'label' => 'Categoria', 'type' => 'select', 'options' => self::sponsor_categories(), 'placeholder' => 'Selecione a categoria' ),
						array( 'key' => '_sponsor_type', 'label' => 'Tipo de apoiador', 'type' => 'select', 'options' => self::sponsor_types(), 'placeholder' => 'Selecione o tipo' ),
						array( 'key' => '_sponsor_description', 'label' => 'Descrição', 'type' => 'textarea', 'help' => 'Descreva o apoiador e como ele apoia a comunidade.' ),
					),
				),
				// Responsive carousel artwork: two independent Media Library
				// relationships per Apoiador. The legacy "_sponsor_logo" meta
				// is NOT rendered here — it became the Imagem Desktop fallback
				// for records created before the two-field model (see
				// Conexao_Admin_Ux_Fields::get_value()).
				'imagens'   => array(
					'title'    => 'Imagens do carousel',
					'icon'     => 'dashicons-format-image',
					'priority' => 15,
					'fields'   => array(
						array(
							'key'   => '_sponsor_desktop_image',
							'label' => 'Imagem Desktop',
							'type'  => 'media',
							'help'  => 'Usada no carousel em telas maiores (tablets e desktops). Prefira uma composição horizontal/landscape — aproximadamente 16:9 (ex.: 1280×720 ou 1600×900).',
						),
						array(
							'key'   => '_sponsor_mobile_image',
							'label' => 'Imagem Mobile',
							'type'  => 'media',
							'help'  => 'Usada no carousel em telas pequenas (celulares). Prefira uma composição vertical/portrait — aproximadamente 3:4 ou 4:5 (ex.: 800×1000 ou 900×1200). Se vazia, a Imagem Desktop é usada como alternativa.',
						),
					),
				),
				'link'      => array(
					'title'    => 'Link',
					'icon'     => 'dashicons-admin-links',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_sponsor_link', 'label' => 'Link do Apoiador', 'type' => 'url', 'help' => 'Informe o endereço do site ou página do apoiador. Ao clicar no apoiador, o visitante será direcionado para este link.' ),
					),
				),
				'exibicao'  => array(
					'title'    => 'Exibição',
					'icon'     => 'dashicons-visibility',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_sponsor_featured', 'label' => 'Apoiador em destaque', 'type' => 'checkbox', 'help' => 'Exibir na página inicial e/ou no topo da lista.' ),
						array( 'key' => '_sponsor_display_order', 'label' => 'Ordem de exibição', 'type' => 'number', 'help' => 'Número menor aparece primeiro. Ex.: 1, 2, 3…' ),
					),
				),
			),
			'publishing' => array(
				'statuses' => array(
					'draft'     => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'needs_review' => array( 'label' => 'Revisão', 'badge' => 'review' ),
					'published' => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'archived'  => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				'status_meta' => '_sponsor_status',
				'default_status' => 'draft',
			),
			'columns'    => array(
				'category' => array( 'label' => 'Categoria', 'render' => 'category' ),
				'featured' => array( 'label' => 'Destaque', 'meta' => '_sponsor_featured', 'format' => 'featured' ),
				'status'   => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions' => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'category' => array( 'label' => 'Atribuir categoria', 'type' => 'taxonomy', 'taxonomy' => 'conexao_category' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente os apoiadores selecionados? Esta ação não pode ser desfeita.' ),
			),
			'summary'    => array(
				array( 'key' => 'published', 'label' => 'Publicados', 'status' => 'published' ),
				array( 'key' => 'review', 'label' => 'Revisão', 'status' => 'needs_review' ),
			),
		);
	}

	/**
	 * ------------------------------------------------------------------
	 * CURSOS (Provedores de cursos / institutions)
	 * ------------------------------------------------------------------
	 *
	 * The Cursos section is a curated directory of course providers and
	 * learning platforms. Each provider is a single record that links directly
	 * to the provider's own website. We intentionally do NOT import individual
	 * courses; all course dates, prices, schedules and enrollment live on the
	 * external provider website.
	 */
	private static function course_provider_config() {
		return array(
			'post_type'  => 'course_provider',
			'labels'     => array(
				'singular'        => 'Provedor de Cursos',
				'plural'          => 'Provedores de Cursos',
				'add_button'      => 'Adicionar Provedor',
				'add_new_item'    => 'Adicionar Provedor',
				'edit_item'       => 'Editar Provedor',
				'empty_title'     => 'Ainda não existem provedores de cursos',
				'empty_message'   => 'Adicione o primeiro provedor de cursos à diretoria da página Cursos.',
				'success_saved'   => 'Provedor atualizado com sucesso.',
				'success_created' => 'Provedor criado com sucesso.',
				'success_published' => 'Provedor publicado com sucesso.',
				'success_draft'   => 'Rascunho salvo com sucesso.',
				'success_duplicated' => 'Provedor duplicado como rascunho.',
				'success_archived' => 'Provedor arquivado com sucesso.',
				'success_bulk'    => 'Provedores atualizados com sucesso.',
			),
			'date_meta'  => '_provider_created_date',
			'sections'   => array(
				'principal' => array(
					'title'    => 'Informações principais',
					'icon'     => 'dashicons-welcome-learn-more',
					'priority' => 10,
					'fields'   => array(
						array( 'key' => '_provider_name', 'label' => 'Nome', 'type' => 'text', 'required' => true, 'help' => 'Nome do provedor de cursos ou instituição. Ex.: "FETCH Courses".' ),
						array( 'key' => '_provider_description', 'label' => 'Descrição', 'type' => 'textarea', 'help' => 'Descrição curta em português do que este provedor oferece.' ),
						array( 'key' => '_provider_logo', 'label' => 'Logo', 'type' => 'media', 'help' => 'Logo oficial do provedor. Recomendado: logo da instituição (PNG/JPEG).' ),
						array( 'key' => '_provider_category', 'label' => 'Categoria', 'type' => 'select', 'options' => self::provider_categories(), 'placeholder' => 'Selecione a categoria' ),
						array( 'key' => '_provider_location', 'label' => 'Localização', 'type' => 'text', 'help' => 'Localização (ex.: "Irlanda" ou "Online / Irlanda").' ),
					),
				),
				'link'      => array(
					'title'    => 'Link do curso / instituição',
					'icon'     => 'dashicons-external',
					'priority' => 20,
					'fields'   => array(
						array( 'key' => '_provider_url', 'label' => 'Link do Curso / Instituição', 'type' => 'url', 'required' => true, 'help' => 'Endereço do site oficial do provedor. Ao clicar no cartão, o visitante é direcionado para este link em uma nova aba.' ),
					),
				),
				'exibicao'  => array(
					'title'    => 'Exibição',
					'icon'     => 'dashicons-visibility',
					'priority' => 30,
					'fields'   => array(
						array( 'key' => '_provider_order', 'label' => 'Ordem de exibição', 'type' => 'number', 'help' => 'Número menor aparece primeiro. Ex.: 1, 2, 3…' ),
					),
				),
			),
			'publishing' => array(
				'statuses' => array(
					'draft'     => array( 'label' => 'Rascunho', 'badge' => 'draft' ),
					'needs_review' => array( 'label' => 'Revisão', 'badge' => 'review' ),
					'published' => array( 'label' => 'Publicado', 'badge' => 'published' ),
					'archived'  => array( 'label' => 'Arquivado', 'badge' => 'archived' ),
				),
				'status_meta' => '_provider_status',
				'default_status' => 'draft',
			),
			'columns'    => array(
				'category' => array( 'label' => 'Categoria', 'meta' => '_provider_category' ),
				'location' => array( 'label' => 'Localização', 'meta' => '_provider_location' ),
				'status'   => array( 'label' => 'Status', 'render' => 'status' ),
			),
			'bulk_actions' => array(
				'publish'  => array( 'label' => 'Publicar', 'type' => 'status', 'value' => 'published' ),
				'draft'    => array( 'label' => 'Rascunho', 'type' => 'status', 'value' => 'draft' ),
				'archive'  => array( 'label' => 'Arquivar', 'type' => 'status', 'value' => 'archived' ),
				'delete'   => array( 'label' => 'Excluir permanentemente', 'type' => 'delete', 'confirm' => 'Tem certeza que deseja excluir permanentemente os provedores selecionados? Esta ação não pode ser desfeita.' ),
			),
			'summary'    => array(
				array( 'key' => 'published', 'label' => 'Publicados', 'status' => 'published' ),
				array( 'key' => 'review', 'label' => 'Revisão', 'status' => 'needs_review' ),
			),
		);
	}
}