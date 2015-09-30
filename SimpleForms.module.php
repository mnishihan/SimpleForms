<?php

/**
 * SimpleForms for ProcessWire
 * A simple form processor. Uses AJAX, configurable with JSON. Front-end is up to you.
 *
 * Module
 *
 * Copyright (c) 2015, Mike Rockett. All Rights Reserved.
 * Licence: MIT License - http://mit-license.org/
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'helpers.php';

/**
 * WireData/SimpleForms
 */
class SimpleForms extends WireData implements Module
{
    /**
     * Requested (elected) form name.
     * @var string
     */
    protected $electedFormName = '';

    /**
     * Current form's config.
     * @var stdClass
     */
    protected $form;

    /**
     * All forms and their configs.
     * @var [type]
     */
    protected $forms;

    /**
     * Path to forms.
     * @var string
     */
    protected $formsPath = '';

    /**
     * Module path
     * @var string
     */
    protected $path = '';

    /**
     * Module URL
     * @var string
     */
    protected $url = '';

    /**
     * Form action prefix
     * @var string
     */
    protected $formActionPrefix = 'module/simple-forms';

    /**
     * HTTP Referrer
     * @var string
     */
    protected $httpReferrer = '';

    /**
     * Response (noAJAX)
     * @var string
     */
    public $response = '';

    /**
     * Form was successfully processed.
     * @var bool
     */
    public $successful = false;

    /**
     * Initialise the module:
     * Add hook if valid request and set tokens.
     * @return void
     */
    public function init()
    {
        // SimpleForms fuel.
        $this->wire('simpleForms', $this);

        // Set paths.
        $this->path = $this->config->paths->siteModules . __CLASS__;
        $this->url = $this->config->urls->siteModules . __CLASS__;
        $this->formsPath = truePath("{$this->path}/forms");

        // Set CSRF token data.
        $this->input->tokenName = $this->session->CSRF->getTokenName();
        $this->input->tokenValue = $this->session->CSRF->getTokenValue();

        // Proceed only if request is valid.
        if ($this->validRequest()) {
            $this->prepare();
            $this->addHookBefore("ProcessPageView::pageNotFound", $this, "processForm");
        }
    }

    /**
     * Determine if a request is valid.
     * Valid requests are AJAX POSTs to module/simple-forms/<existing-form>.
     * @return bool
     */
    protected function validRequest()
    {
        // AJAX-check
        if (!$this->config->ajax) {
            // If a response is saved in the session (that is, AJAX is not being used),
            // then we're not processing a form. We can simply pass this data (manipulated
            // the same way the front-end plugin does it) to $simpleForms along with the input,
            // clear the variables from the session, and return false for this request.
            //
            // This is simply a way to flash data upon redirect as the functionality is
            // not yet present in ProcessWire.
            if ($this->session->response) {
                // Pass input to $simpleForms for template processing.
                if (isset($this->session->response->success)) {
                    $this->successful = true;
                }
                $this->oldData = $this->session->oldData;
                $this->session->remove('oldData');
                $this->response = $this->session->response;
                $this->session->remove('response');
                if (isset($this->response->error) && isset($this->response->errors)) {
                    $count = count((array) $this->response->errors);
                    $this->response->error = plate(
                        $this->response->error,
                        toWords($count),
                        $count
                    );
                }
            }
            // Otherwise, store the referrer - we'll need this for redirection purposes.
            $this->httpReferrer = $_SERVER['HTTP_REFERER'];
        }

        // Route/Form-check
        $routeExpression = '%' . preg_quote($this->formActionPrefix) . '/([a-z-_]+)/?%';
        $route = trim((isset($_GET['it'])) ? $_GET['it'] : $_SERVER['REQUEST_URI'], '/');
        if (!preg_match($routeExpression, $route)) {
            return false;
        } else {
            $this->electedFormName = $this->sanitizer->varName(preg_replace($routeExpression, "\\1", $route));
        }

        // POST-check
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        return true;
    }

    /**
     * Prepare all the things.
     * @return void
     */
    protected function prepare()
    {
        // Check for 'forms' directory.
        if (!is_dir($this->formsPath)) {
            $this->respond(['error' => '[forms] directory missing...'], 500);
        }

        // If it exists, iterate over it, assigning accepted forms.
        $directory = new DirectoryIterator($this->formsPath);
        foreach ($directory as $info) {
            if ($info->isDir() && !$info->isDot()) {
                $forms[] = $info->getFilename();
            }
        }

        // Stop if there are no definitions.
        if (empty($forms)) {
            $this->respond(['error' => 'There are no forms to work with.'], 500);
        }

        // Loop through each defined form and setup configuration.
        foreach ($forms as $form) {
            $configFilename = truePath("{$this->formsPath}/{$form}/config.json");
            if (!is_file($configFilename)) {
                $this->respond(['error' => "[{$form}] has no config.json file."], 500);
            }
            $config = json_decode(file_get_contents($configFilename));
            if (is_null($config)) {
                $this->respond(['error' => "JSON config for [{$form}] appears to be invalid. Please check syntax."], 500);
            }
            $this->forms = new stdClass;
            $this->forms->{$form} = $config;
        }

        // Stop if the elected form was not defined.
        if (!isset($this->forms->{$this->electedFormName})) {
            $this->respond(['error' => "[{$this->electedFormName}] has not been defined."], 500);
        }

        $this->form = $this->forms->{$this->electedFormName};
        $this->form->name = $this->electedFormName;

        // Validate the configuration object.
        $configValid = (
            isset($this->form->title) &&
            isset($this->form->fields) &&
            count($this->form->fields) > 0 &&
            isset($this->form->emails) &&
            count($this->form->emails) > 0
        );
        if (!$configValid) {
            $this->respond(['error' => "[{$this->electedFormName}] config does not meet the minimum requirements."], 500);
        }
    }

    /**
     * Send a response.
     * @return void
     */
    protected function respond($data, $responseCode = 200)
    {
        // If using AJAX, send a JSON response. Otherwise, flash the response to
        // the session for template output.
        if ($this->config->ajax) {
            http_response_code($responseCode);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');
            print json_encode($data);
            exit;
        } else {
            http_response_code($responseCode);
            $this->session->response = json_decode(json_encode($data)); // Quick array>object conversion
            $oldData = new stdClass;
            foreach ($this->input->post as $name => $value) {
                if (stripos($name, 'TOKEN') !== 0) {
                    $oldData->$name = $value;
                }
            }
            $this->session->oldData = $oldData;
            $this->session->redirect($this->httpReferrer, false);
        }
    }

    /**
     * Process the applicable form.
     * @return string JSON-encoded
     */
    protected function processForm()
    {
        // Before anything, check CSRF.
        try {
            $this->session->CSRF->validate();
        } catch (WireCSRFException $e) {
            $this->respond(['error' => 'Request appears to be forged.'], 500);
        }

        // Get and construct the validator
        require_once truePath(__DIR__ . '/packages/autoload.php');
        $validator = new Violin\Violin();

        $formInput = [];
        $acceptedFields = [];

        // Prepare validations and messages for Violin
        foreach ($this->form->fields as $field => $fieldData) {

            // Assign the ruleBag.
            $ruleBag = $fieldData->rules;

            // Import the rules, as specified in the keys for the field.
            $rules = implode('|', array_keys((array) $ruleBag));

            // Get and sanitize the input for the field, or just set to blank if none provided.
            $input = (isset($this->input->post->{$field})) ? $this->input->post->{$field} : '';
            if (isset($fieldData->sanitize) && !empty($input)) {
                foreach (explode('|', $fieldData->sanitize) as $sanitizer) {
                    $input = $this->sanitizer->{$sanitizer}($input);
                    // As $sanitizer strips invalid data, we need to put it back for the validator.
                    if (empty($input)) {
                        $input = $this->input->post->{$field};
                    }
                }
            }

            // Add to formInput for template processing (we're only importing valid POST vars here).
            $formInput[$field] = $input;

            // Add the field's validation rules.
            $validations[$field] = [$input, $rules];

            // Allow the input.
            $acceptedFields[] = $field;

            // Remove the rule params, if any, for the field messages.
            array_walk($ruleBag, function ($message, $rule) use ($field, &$messages) {
                $messages[$field][explode('(', $rule)[0]] = $message;
            });
        }

        // Send everything off to Violin
        $validator->addFieldMessages($messages);
        $validator->validate($validations);

        // If validation fails, respond with the errors.
        if (!$validator->passes()) {
            $errors['error'] = isset($this->form->messages->validation) ? $this->form->messages->validation : "Invalid input. Please check, and try again.";
            foreach ($acceptedFields as $field) {
                if ($validator->errors()->has($field)) {
                    $errors['errors'][$field] = $validator->errors()->first($field);
                }
            }
            $this->respond($errors, 422);
        }

        // If no emails have been defined, throw an error.
        if (!isset($this->form->emails)) {
            $this->respond(['error' => "You haven't set up the emails to send for this form."], 500);
        }

        // Set up the template engine
        $templateEngine = new StringTemplate\Engine();

        // Replace empty input with 'Not Provided'.
        array_walk($formInput, function (&$value, $key) {
            if (empty($value)) {
                $key = $this->sanitizer->varName($key);
                if (isset($this->form->fields->{$key}->default) && !empty($this->form->fields->{$key}->default)) {
                    $value = $this->form->fields->{$key}->default;
                } else {
                    $value = 'Not Provided';
                }
            }
        });

        // Initialise the data array.
        $data = [];
        if (isset($this->form->info)) {
            $data['info'] = (array) $this->form->info;
        }
        $data['title'] = $this->form->title;

        // Initialise the result array
        $results = [];

        // Start the email loop.
        foreach ($this->form->emails as $emailKey => $email) {

            // Throw an error if a template hasn't been defined for the email
            if (!isset($email->template)) {
                $this->respond(['error' => "[$emailKey] needs a template."], 500);
            }
            // Otherwise, set up the template file paths
            $templateFileBase = truePath("{$this->formsPath}/{$this->form->name}/templates/{$email->template}");
            $templates = (object) [
                'plain' => "{$templateFileBase}.txt",
                'html' => "{$templateFileBase}.html",
            ];

            // Should the plain template not exist, throw an error.
            // The HTML template is optional.
            if (!is_file($templates->plain)) {
                $this->respond(['error' => "Plain template for [$emailKey] does not exist, but is required."], 500);
            }

            // Should the HTML template not exist, unset it from the array.
            if (!is_file($templates->html)) {
                unset($templates->html);
            }

            // Start wireMail.
            $mailer = $this->sanitizer->varName($emailKey) . "Mailer";
            $$mailer = wireMail();

            // Check for to/from headers.
            foreach (['to', 'from'] as $header) {
                // If it hasn't been set, throw an error.
                if (!isset($email->{$header})) {
                    $this->respond(['error' => "'{$header}' not set for [$emailKey]."], 500);
                }

                // Otherwise, do any necessary replacements regarding input.
                $email->{$header} = $templateEngine->render($email->{$header}, ['input' => $formInput]);
            }

            // If the subject has not been set, default it to the name of the form.
            if (!isset($email->subject)) {
                $email->subject = $this->form->name;
            }

            // Add subject and input to the data array.
            $data['subject'] = $email->subject;
            $data['input'] = $formInput;

            // Prepare basic wireMail headers.
            $$mailer->from($email->from)->to($email->to)->subject($email->subject);

            // If an HTML template is specified and exists:
            if (isset($templates->html) && is_file($templates->html)) {

                // If any multiline fields have been defined, convert them for HTML output.
                // Note: should an HTML template be present, the field should be called as normal:
                //       {input.field} instead of {input.field.html} and {input.field.plain}
                foreach ($this->form->fields as $field => $fieldData) {
                    if (isset($fieldData->textField)) {
                        $plain = $data['input'][$field];
                        $html = str_replace(array("\r\n", "\r", "\n"), "<br>", $plain);
                        $data['input'][$field] = [
                            'plain' => $plain,
                            'html' => $html,
                        ];
                    }
                }

                // Build stylesheets array.
                $directory = new DirectoryIterator(truePath("{$this->formsPath}/{$this->form->name}/templates"));
                $stylesheets = [];
                /**
                 * Minify CSS.
                 * @param  string $buffer
                 * @return string
                 */
                $minify = function ($buffer) {
                    $buffer = preg_replace("%((?:/\*(?:[^*]|(?:\*+[^*/]))*\*+/)|(?:/.*))%", "", $buffer);
                    $buffer = preg_replace('%\s+%', ' ', $buffer);
                    $buffer = str_replace(["\r\n", "\r", "\t", "\n"], '', $buffer);
                    $buffer = str_replace(['; ', ': ', ' {', '{ ', ', ', '} ', ';}'], [';', ':', '{', '{', ',', '}', '}'], $buffer);
                    return $buffer;
                };
                foreach ($directory as $info) {
                    if ($info->isFile() && !$info->isDot()) {
                        if ($info->getExtension() === 'css') {
                            $stylesheets[pathinfo($info->getFilename(), PATHINFO_FILENAME)] =
                            '<style>' . $minify(file_get_contents($info->getPathname())) . '</style>';
                        }
                    }
                }
                $data['stylesheets'] = $stylesheets;

                // Set the HTML body.
                $$mailer->bodyHTML($templateEngine->render(file_get_contents($templates->html), $data));
            }

            // Set the plain body.
            $$mailer->body($templateEngine->render(file_get_contents($templates->plain), $data));

            // Send the email.
            $result = $$mailer->send();
            if ($result === 0) {
                $this->respond(['error' => "Could not send [$emailKey]."], 500);
            }
            $results[] = $emailKey;
        }

        $this->respond([
            'success' => isset($this->form->messages->success) ? $this->form->messages->success : "Email(s) sent.",
            'sent' => $results,
        ]);
    }

    /**
     * Get script file path.
     * @param  string  $name      defaults to 'simpleforms' for main plugin
     * @param  boolean $jquery    define if this file has 'jquery.' as a prefix
     * @param  boolean $min       define if this file is minified
     * @param  boolean $cacheBust cache buster (adds unix timestamp to filename)
     * @return string             resulting file name
     */
    public function script($name = 'simpleforms', $jquery = true, $min = true, $cacheBust = false)
    {
        $jquery = ($jquery === true) ? 'jquery.' : '';
        $now = ($cacheBust) ? '?' . time() : '';
        $min = ($min) ? '.min' : '';
        return "{$this->url}/assets/{$jquery}{$name}{$min}.js{$now}";
    }

    /**
     * Get the action URI for a form
     * @param  string $formName
     * @return string
     */
    public function actionFor($formName)
    {
        return "{$this->config->urls->root}{$this->formActionPrefix}/{$formName}";
    }

    /**
     * Output CSRF token data to form
     * @return [type] [description]
     */
    public function csrfToken()
    {
        return '<input type="hidden" name="' . $this->input->tokenName . '" value="' . $this->input->tokenValue . '">' . PHP_EOL;
    }

    /**
     * Render a form based on its config.
     * @param  string $name The name of the form (as defined by its directory name, varName sanitized)
     * @return string
     */
    public function render($name)
    {
        // Coming soon...
    }
}