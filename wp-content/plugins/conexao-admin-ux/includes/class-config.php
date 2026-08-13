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
	const SUPPORTED_TYPES = array( 'event', 'guide', 'job', 'sponsor' );

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
				'event'    => self::event_config(),
				'guide'    => self::guide_config(),
				'job'      => self::job_config(),
				'sponsor'  => self::sponsor_config(),
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
					'needs_review'     => array( 'label' => 'Revisão', 'badge' => 'review' ),
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
				array( 'key' => 'review', 'label' => 'Revisão', 'status' => 'needs_review' ),
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
						array( 'key' => '_sponsor_logo', 'label' => 'Logo', 'type' => 'media', 'help' => 'Logo do apoiador (recomendado: PNG/JPEG transparente).' ),
						array( 'key' => '_sponsor_category', 'label' => 'Categoria', 'type' => 'select', 'options' => self::sponsor_categories(), 'placeholder' => 'Selecione a categoria' ),
						array( 'key' => '_sponsor_type', 'label' => 'Tipo de apoiador', 'type' => 'select', 'options' => self::sponsor_types(), 'placeholder' => 'Selecione o tipo' ),
						array( 'key' => '_sponsor_description', 'label' => 'Descrição', 'type' => 'textarea', 'help' => 'Descreva o apoiador e como ele apoia a comunidade.' ),
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
}
