<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Course discoverability - Language pack (Brazilian Portuguese)
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['applydefault'] = 'Aplicar o padrão aos cursos existentes';
$string['applydefault_action'] = 'Aplicar aos cursos existentes';
$string['applydefault_changed'] = 'A descobribilidade padrão de cursos novos mudou desde que esta tarefa foi agendada. Nenhum curso foi alterado.';
$string['applydefault_confirm'] = '{$a->count} cursos criados antes de {$a->date} não têm descobribilidade própria. Cada um deles passará a {$a->state}, em uma tarefa em segundo plano que roda com o próximo cron.

Essa data é quando o padrão de cursos novos passou a {$a->state} na página de configurações, ou agora, se a mudança não deixou registro lá. Os cursos criados desde então receberam o padrão, ou uma escolha feita por alguém, e ficam como estão, assim como os cursos cuja descobribilidade alguém definiu e a página inicial do site. Um curso que alguém voltou a Listado antes dessa data também não tem descobribilidade própria e passará a {$a->state} também.';
$string['applydefault_done'] = 'Descobribilidade padrão aplicada: {$a->applied} cursos alterados, {$a->skipped} ignorados porque alguém definiu a descobribilidade deles nesse meio-tempo, {$a->failed} com falha.';
$string['applydefault_listed'] = 'A descobribilidade padrão de cursos novos é Listado, que é o que um curso sem descobribilidade própria já é; não há nada a aplicar. Escolha Não listado como padrão primeiro.';
$string['applydefault_next'] = 'A próxima página mostra quantos cursos serão alterados e pede confirmação antes de mudar qualquer um.';
$string['applydefault_none'] = 'Todo curso criado antes de {$a->date} já tem descobribilidade própria. Não há nada a alterar.';
$string['applydefault_queued'] = 'A descobribilidade padrão está sendo aplicada aos cursos existentes. Esta página se atualiza quando a tarefa terminar.';
$string['applydefault_reach'] = 'O padrão acima vale só para os cursos criados a partir de agora. Os cursos que já existem e nunca tiveram a descobribilidade definida continuam como Listado.';
$string['applydefault_savefirst'] = 'Salve as mudanças antes: a ação usa o padrão salvo.';
$string['applydefault_stale'] = 'Nada foi agendado: a descobribilidade padrão de cursos novos foi salva de novo, ou a tarefa já foi agendada, desde que a página foi exibida. Revise os cursos que ela alteraria e confirme de novo.';
$string['applydefault_task'] = 'Aplicar a descobribilidade padrão aos cursos existentes';
$string['categorystate'] = 'Descobribilidade';
$string['categorystate_help'] = 'Quem pode saber que esta categoria existe.

* **Listada**: a categoria e seus cursos aparecem nas listagens como qualquer outra.
* **Não listada**: a categoria só é nomeada a quem é membro de uma coorte definida nesta categoria, a quem tem um papel nesta categoria ou em uma categoria acima dela, e à equipe (gerentes e criadores de curso). Todas as demais pessoas deixam de ver a categoria, suas subcategorias e seus cursos no índice de cursos, na página inicial e nos resultados de busca; um curso dentro dela continua nas listagens de quem já está inscrito nele (inclusive com uma inscrição que começa em data futura), tem uma candidatura aguardando decisão ou na lista de espera, ou o leciona. Um curso público dentro de uma categoria não listada deixa de ser público, mas mantém o estado Público: volta a ser público assim que ele, ou uma subcategoria que o contém, é movido para uma categoria que não é não listada nem oculta, ou assim que esta categoria volta a ser listada, o que só um usuário autorizado a publicar esse curso pode fazer, mas não quando esta categoria é excluída com o conteúdo movido: isso o leva de volta a Listado. Confira a descobribilidade dos cursos e subcategorias daqui antes de movê-los para fora.
* **Pública**: além de listada, a página da categoria pode ser lida por visitantes não autenticados. Dentro dela, só os cursos cujo próprio estado é Público são servidos a eles: um curso não listado continua oculto, e um curso listado não é oferecido a um visitante. Só um usuário autorizado a publicar categorias pode marcar ou desmarcar isto. Uma categoria oculta, uma categoria dentro de uma categoria oculta e uma categoria dentro de uma categoria não listada nunca são públicas, diga o que disser esta opção.

Isto é uma regra de listagem, não uma permissão. Esconde a categoria das páginas que este site renderiza; serviços web, o aplicativo móvel, o bloco de Navegação e os menus inteligentes de lista de cursos continuam nomeando-a, e um link direto para um curso continua seguindo as regras do próprio curso. Coortes criadas no nível do site não contam, e a coorte à qual um método de inscrição restringe as inscrições também não: só uma coorte definida nesta categoria, ou um papel atribuído aqui, abre a categoria.';
$string['categorystate_saved'] = 'Descobribilidade da categoria salva';
$string['categorystate_stale'] = 'Nada foi salvo: o valor enviado não está entre as opções que esta categoria oferece agora, em geral porque alguém alterou a descobribilidade dela desde que a página foi exibida. Revise o estado atual e salve de novo.';
$string['datasource:categories'] = 'Categorias de curso e sua descobribilidade';
$string['datasource:courses'] = 'Cursos e sua descobribilidade';
$string['defaultstate'] = 'Descobribilidade padrão de cursos novos';
$string['defaultstate_desc'] = 'A descobribilidade que um curso novo recebe quando quem o cria não escolhe uma. É o valor inicial do campo Descobribilidade no formulário do curso e o estado dado a um curso criado por serviço web, por envio de CSV, pela aprovação de um pedido de curso ou por qualquer outro caminho que não preencha esse campo. O valor escolhido no formulário do curso sempre prevalece. Público não está entre as opções: publicar um curso exige a permissão de publicar cursos de quem o cria. Uma alteração vale só para os cursos criados depois dela.';
$string['entity:category_state'] = 'Descobribilidade da categoria';
$string['entity:category_state_user'] = 'Usuário que alterou a descobribilidade da categoria por último';
$string['entity:course_state'] = 'Descobribilidade do curso';
$string['entity:course_state_user'] = 'Usuário que alterou a descobribilidade do curso por último';
$string['event_category_state_updated'] = 'Estado de descobribilidade da categoria de curso atualizado';
$string['event_course_state_updated'] = 'Estado de descobribilidade do curso atualizado';
$string['event_public_state_kept_after_move'] = 'Estado público de descobribilidade mantido após a exclusão de uma categoria';
$string['pluginname'] = 'Descobribilidade de cursos';
$string['preview_ancestor'] = 'Uma categoria acima desta está não listada, então a regra dela também vale aqui: a pessoa precisa satisfazer todas as categorias não listadas do caminho.';
$string['preview_cohortmembers'] = 'Membros: {$a}';
$string['preview_cohorts'] = 'Coortes definidas nesta categoria';
$string['preview_cohortsmore'] = 'mais {$a} não mostradas';
$string['preview_cohortsnone'] = 'Nenhuma coorte está definida nesta categoria. Coortes criadas no nível do site não contam aqui, e a coorte à qual um método de inscrição restringe as inscrições também não: crie uma coorte nesta categoria, ou atribua papéis aqui.';
$string['preview_cohortsnoview'] = 'Você não pode ver as coortes desta categoria.';
$string['preview_heading'] = 'Quem vê esta categoria enquanto ela está não listada';
$string['preview_managecohorts'] = 'Gerenciar as coortes desta categoria';
$string['preview_public'] = 'Esta categoria é pública: a página dela pode ser lida por visitantes não autenticados. Dentro dela, só os cursos cujo próprio estado é Público são servidos a eles; um curso não listado e uma subcategoria não listada continuam ocultos, e um curso listado não é oferecido a um visitante.';
$string['preview_publicancestor'] = 'Uma categoria acima desta está não listada, então esta categoria não é pública de fato: um visitante não autenticado não satisfaz nenhum dos critérios que abrem uma categoria não listada, e essa regra vale para tudo abaixo dela.';
$string['preview_publicheading'] = 'O que os visitantes veem desta categoria pública';
$string['preview_publichidden'] = 'Esta categoria, ou uma categoria acima dela, está oculta. Uma categoria oculta nunca é pública, diga o que disser esta opção.';
$string['preview_relist'] = 'Voltar a listar esta categoria, ou torná-la pública, torna público de fato o que está público abaixo dela e retido apenas por ela: isso passará a ser servido a visitantes não autenticados. Cursos públicos: {$a->courses}. Subcategorias públicas: {$a->categories}.';
$string['preview_relistblocked'] = 'Você não pode voltar a listar esta categoria nem torná-la pública: você não tem permissão para publicar todos esses cursos e subcategorias. Alguém que possa publicá-los precisa fazer isso.';
$string['preview_roleholders'] = '{$a} pessoas têm um papel nesta categoria ou em uma categoria acima dela.';
$string['preview_theme'] = 'Temas por categoria estão habilitados neste site (allowcategorythemes). Um tema definido nesta categoria tiraria as páginas dela do tema que a oculta.';
$string['preview_visible'] = 'Além da equipe, esta categoria fica visível para {$a} pessoas enquanto não for listada.';
$string['preview_visiblenone'] = 'Não listada e visível para ninguém além da equipe: nenhum membro de coorte e nenhum detentor de papel. Todos os demais usuários deixarão de ver esta categoria e seus cursos.';
$string['privacy:metadata:local_unlistedcourses_catstate'] = 'O estado de descobribilidade de cada categoria de curso que não está no estado padrão, e quem o alterou por último.';
$string['privacy:metadata:local_unlistedcourses_catstate:categoryid'] = 'A categoria de curso à qual o estado pertence.';
$string['privacy:metadata:local_unlistedcourses_catstate:state'] = 'O estado: listada, não listada ou pública.';
$string['privacy:metadata:local_unlistedcourses_catstate:timemodified'] = 'Quando o estado foi alterado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_catstate:usermodified'] = 'O usuário que alterou o estado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_state'] = 'O estado de descobribilidade de cada curso que não está no estado padrão, e quem o alterou por último.';
$string['privacy:metadata:local_unlistedcourses_state:courseid'] = 'O curso ao qual o estado pertence.';
$string['privacy:metadata:local_unlistedcourses_state:state'] = 'O estado: listado, não listado ou público.';
$string['privacy:metadata:local_unlistedcourses_state:timemodified'] = 'Quando o estado foi alterado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_state:usermodified'] = 'O usuário que alterou o estado pela última vez.';
$string['publiceffect'] = 'Efeito do estado público';
$string['publiceffect_effective'] = 'Em vigor: servido a visitantes';
$string['publiceffect_hiddencategory'] = 'Retido: dentro de uma categoria oculta';
$string['publiceffect_hiddenitself_category'] = 'Retido: a categoria está oculta';
$string['publiceffect_hiddenitself_course'] = 'Retido: o curso está oculto';
$string['publiceffect_none'] = 'Não é público';
$string['publiceffect_unlistedcategory'] = 'Retido: dentro de uma categoria não listada';
$string['restore_publicclamped'] = 'O curso era público no backup, mas quem o está restaurando não pode publicar cursos aqui. O estado público não foi aplicado.';
$string['restore_statebusy'] = 'O estado de descobribilidade do backup não foi aplicado: outra pessoa estava alterando a descobribilidade do curso de destino ao mesmo tempo. O curso mantém a descobribilidade que tem.';
$string['restore_statenotapplied'] = 'O estado de descobribilidade do backup não foi aplicado: quem o está restaurando não pode alterar o estado do curso de destino.';
$string['restoredefaultstate'] = 'Descobribilidade de curso restaurado de backup sem esse dado';
$string['restoredefaultstate_desc'] = 'A descobribilidade que um curso recebe quando um backup é restaurado como curso novo e não traz descobribilidade, como acontece com um backup feito em um site sem este plugin. Um backup cujo valor esta versão aplica o mantém; um cujo valor é desconhecido aqui, ou é público e foi recusado a quem restaura, também recebe o padrão. Uma restauração em um curso existente deixa o estado desse curso como está. Um curso criado por envio de CSV a partir de um backup modelo é criado antes da restauração, por isso recebe o padrão de cursos novos.';
$string['state'] = 'Descobribilidade';
$string['state_default'] = 'Listado';
$string['state_help'] = 'Quem pode saber que este curso existe.

* **Listado**: o curso aparece nas listagens e na página de inscrição como qualquer outro.
* **Não listado**: o curso só é mostrado a quem está inscrito (inclusive com uma inscrição que começa em data futura), tem uma candidatura aguardando decisão ou na lista de espera, pode se inscrever agora, será inscrito ao concluir outro curso ou faz parte da equipe. Uma inscrição suspensa ou encerrada não conta. Continua visível para essas pessoas e funciona por link direto. Não é o mesmo que ocultar o curso, que o torna indisponível para todos.
* **Público**: além de listado, a página de apresentação do curso pode ser lida por visitantes não autenticados, de modo que um link compartilhado mostra uma prévia em aplicativos de mensagem e redes sociais. Só um usuário autorizado a publicar cursos pode marcar ou desmarcar isto. Um curso oculto, ou dentro de uma categoria oculta, nunca é público, diga o que disser esta opção. Se o curso não tem página de apresentação configurada, esta opção não produz efeito visível até que tenha.';
$string['state_public'] = 'Público';
$string['state_unlisted'] = 'Não listado';
$string['statebusy'] = 'A descobribilidade não foi salva: outra pessoa estava alterando a descobribilidade deste curso ou desta categoria ao mesmo tempo. Tente de novo em instantes.';
$string['statetimemodified'] = 'Última alteração da descobribilidade';
$string['unlistedcourses:managecategorystate'] = 'Alterar o estado de descobribilidade de uma categoria de curso';
$string['unlistedcourses:publish'] = 'Publicar um curso para visitantes não autenticados';
$string['unlistedcourses:publishcategory'] = 'Publicar uma categoria de curso para visitantes não autenticados';
