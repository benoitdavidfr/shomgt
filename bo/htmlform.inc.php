<?php
/** Formulaires HTML composés de un ou plusieurs champs
 *
 * Manque checkedBox
 */
namespace html;
require_once __DIR__.'/lib.inc.php';

/** Sur-classe des champs de formulaire
 *
 * un champdoit pouvoir s'afficher avec un paramètre name et doit exposer son champ label */
abstract readonly class Field {
  /** pour faire plaisir à phpstan */
  function __construct(public string $label='') { }
  /** retourne le code Html */
  abstract function toString(string $name): string;
};

/** Formulaire Html */
readonly class Form {
  /** Création d'un formulaire
   * @param array<string,Field> $fields les champs du formulaire avec comme clé le nom du champ
   * @param string $submit le libellé sur le bouton de validation du formulaire
   * @param array<string,string> $hiddens les paramètres cachés et leur valeur
   * @param string $action le script à exécuter
   * @param 'get'|'post' $method le script à exécuter
   */
  function __construct(
    public array $fields=[],
    public string $submit='submit',
    public array $hiddens=[],
    public string $action='',
    public string $method='get') {}
  
  function __toString(): string {
    $multiple = (count($this->fields) > 1);
    $form = ($multiple ? "<table border=1>" : '')
      ."<form action='$this->action' method='$this->method'>\n";
    foreach ($this->hiddens as $hname => $hvalue)
      $form .= "<input type='hidden' name='$hname' value='$hvalue' />\n";
    foreach ($this->fields as $name => $field) {
      $label = $field->label ? $field->label : $name;
      $form .= ($multiple ? "<tr><td>$label</td><td>" : '')
              .$field->toString($name)
              .($multiple ? '</td></tr>' : '');
    }
    return $form
      .($multiple ? "<tr><td colspan=2><center>" : '')
      ."<input type='submit' value='$this->submit'>"
      .($multiple ? "</center></td></tr>" : '')
      .'</form>'
      .($multiple ? '</table>' : '');
  }
};

/** Champ de formulaire input */
readonly class Input extends Field {
  /** Création du champ
   * @param string $label libellé de ce champ dans le formulaire
   * @param string $type type d'entrée
   * @param int $size taille de l'entrée
   * @param string $value valeur par défaut  */
  function __construct(
    public string $label='',
    public string $type='text',
    public int $size=10,
    public string $value='') {}
  
  function toString(string $name): string {
    return "<input type='$this->type' name='$name' size='$this->size' value='".htmlspecialchars($this->value)."' />";
  }
};

/** Champ de formulaire TextArea */
readonly class TextArea extends Field {
  /** Création du champ
   * @param string $label libellé pour indiquer ce champ dans le formulaire
   * @param string $text valeur par défaut
   * @param int $rows nbre de lignes
   * @param int $cols nbre de colonnes */
  function __construct(
    public string $label='',
    public string $text='',
    public int $rows=3,
    public int $cols=50) {}
  
  function toString(string $name): string {
    return "<textarea name='$name' rows='$this->rows' cols='$this->cols'>".htmlspecialchars($this->text)."</textarea>\n";
  }
};

/** Champ de formulaire Select */
readonly class Select extends Field {
  /** Création du champ
   * @param list<string>|array<string,string> $choices soit une liste de nom=libellé, soit un dict [nom => libelléDuChoix]
   * @param string $label libellé pour indiquer ce champ dans le formulaire
   * @param string $selected le nom du choix par défaut
   */
  function __construct(public array $choices, public string $label='', public ?string $selected=null) {}
  
  function toString(string $name): string {
    $form = "<select name='$name'>\n";
    foreach ($this->choices as $choice => $label) {
      if (is_int($choice)) $choice = $label;
      $form .= "<option value='$choice'".($choice==$this->selected ? ' selected' : '').">$label</option>\n";
    }
    return $form."</select>\n";
  }
};

/** Champ de formulaire boutons radio */
readonly class Radio extends Field {
  /** Création du champ
   * @param list<string>|array<string,string> $choices soit une liste de nom=libellé, soit un dict [nom => libelléDuChoix]
   * @param string $label libellé pour indiquer ce champ dans le formulaire
   * @param string $selected le nom du choix par défaut
   */
  function __construct(public array $choices, public string $label='', public ?string $selected=null) {}
  
  function toString(string $name): string {
    $form = "<fieldset>\n"
      ."  <div>\n";
    foreach ($this->choices as $choice => $label) {
      if (is_int($choice)) $choice = $label;
      $form .= "<input type='radio' id='$choice' name='$name' value='$choice' ".($choice==$this->selected?' checked':'')." />\n"
        ."<label for='$choice'>$label</label>\n";
    }
    return $form."</div></fieldset>";
  }
};


if (!\bo\callingThisFile(__FILE__)) return;


echo '$_GET = '; print_r($_GET);

// Utilisation
if (1) { // @phpstan-ignore-line
  echo new Form(
    fields: [
      'yaml'=> new TextArea(label: 'yamllabel', text: $_GET['yaml'] ?? "TEXTE par défaut", rows: 18, cols: 50),
      'name'=> new Input(label: 'textLabel', size: 24, value: $_GET['name'] ?? "valeur par défaut du champ texte"),
      'name2'=> new Input(size: 24, value: $_GET['name2'] ?? "valeur par défaut du champ texte"),
      'type'=> new Select(label:'typeLabel', choices: ['alive', 'deleted', 'choix3'], selected: $_GET['type'] ?? 'deleted'),
      'contact' => new Radio(
        label: 'contact method',
        choices: [
          'email'=> "courrier électronique",
          'phone',
          'mail',
        ],
        selected: $_GET['contact'] ?? 'mail'
      ),
    ],
    submit: 'ajout',
    hiddens: ['action'=> 'insertMapCat', 'mapnum'=> '7348'],
    action: '',
    method: 'get'
  );
}
elseif (1) {
  echo new Form;
}
echo "<a href='?'>Reset</a><br>\n";
