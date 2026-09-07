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

$string['event_category_state_updated'] = 'Estado de descobribilidade da categoria de curso atualizado';
$string['event_course_state_updated'] = 'Estado de descobribilidade do curso atualizado';
$string['pluginname'] = 'Descobribilidade de cursos';
$string['privacy:metadata:local_unlistedcourses_catstate'] = 'O estado de descobribilidade de cada categoria de curso que não está no estado padrão, e quem o alterou por último.';
$string['privacy:metadata:local_unlistedcourses_catstate:categoryid'] = 'A categoria de curso à qual o estado pertence.';
$string['privacy:metadata:local_unlistedcourses_catstate:state'] = 'O estado: listada ou não listada.';
$string['privacy:metadata:local_unlistedcourses_catstate:timemodified'] = 'Quando o estado foi alterado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_catstate:usermodified'] = 'O usuário que alterou o estado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_state'] = 'O estado de descobribilidade de cada curso que não está no estado padrão, e quem o alterou por último.';
$string['privacy:metadata:local_unlistedcourses_state:courseid'] = 'O curso ao qual o estado pertence.';
$string['privacy:metadata:local_unlistedcourses_state:state'] = 'O estado: listado, não listado ou público.';
$string['privacy:metadata:local_unlistedcourses_state:timemodified'] = 'Quando o estado foi alterado pela última vez.';
$string['privacy:metadata:local_unlistedcourses_state:usermodified'] = 'O usuário que alterou o estado pela última vez.';
$string['restore_publicclamped'] = 'O curso era público no backup, mas quem o está restaurando não pode publicar cursos aqui. O estado público não foi aplicado.';
$string['restore_statenotapplied'] = 'O estado de descobribilidade do backup não foi aplicado: quem o está restaurando não pode alterar o estado do curso de destino.';
$string['state'] = 'Descobribilidade';
$string['state_default'] = 'Listado';
$string['state_help'] = 'Quem pode saber que este curso existe.

* **Listado**: o curso aparece nas listagens e na página de inscrição como qualquer outro.
* **Não listado**: o curso só é mostrado a quem está inscrito, tem uma candidatura aguardando decisão, pode se inscrever agora ou faz parte da equipe. Continua visível para essas pessoas e funciona por link direto. Não é o mesmo que ocultar o curso, que o torna indisponível para todos.
* **Público**: além de listado, a página de apresentação do curso pode ser lida por visitantes não autenticados, de modo que um link compartilhado mostra uma prévia em aplicativos de mensagem e redes sociais. Só um usuário autorizado a publicar cursos pode marcar ou desmarcar isto. Um curso oculto, ou dentro de uma categoria oculta, nunca é público, diga o que disser esta opção. Se o curso não tem página de apresentação configurada, esta opção não produz efeito visível até que tenha.';
$string['state_public'] = 'Público';
$string['state_unlisted'] = 'Não listado';
$string['unlistedcourses:managecategorystate'] = 'Definir se uma categoria de curso é listada ou não listada';
$string['unlistedcourses:publish'] = 'Publicar um curso para visitantes não autenticados';
