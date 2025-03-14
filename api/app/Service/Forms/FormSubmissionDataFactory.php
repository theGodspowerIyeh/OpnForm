<?php

namespace App\Service\Forms;

use App\Models\Forms\Form;
use Faker;

class FormSubmissionDataFactory
{
    private ?Faker\Generator $faker;

    /**
     * If true, then format expected by answer endpoint
     * otherwise, format of answer as we store it in the FormSubmission's data
     */
    private bool $answerFormat = true;

    public function __construct(private Form $form)
    {
        $this->faker = Faker\Factory::create();
    }

    public static function generateSubmissionData(Form $form, array $data = [])
    {
        return (new self($form))->createSubmissionData($data);
    }

    public function asFormSubmissionData()
    {
        $this->answerFormat = false;
        return $this;
    }

    public function createSubmissionData($mergeData = [])
    {
        $data = [];

        // for all non-hidden fields in form, create some fake data
        collect($this->form->properties)->each(function ($property) use (&$data) {
            $value = null;
            switch ($property['type']) {
                case 'text':
                    $value = $this->faker->name();
                    break;
                case 'email':
                    $value = $this->faker->unique()->email();
                    break;
                case 'checkbox':
                    $value = $this->faker->randomElement([true, false]);
                    break;
                case 'number':
                    $value = $this->faker->numberBetween();
                    break;
                case 'rating':
                case 'scale':
                    $value = $this->faker->numberBetween(1, 5);
                    break;
                case 'slider':
                    $value = $this->faker->numberBetween(0, 50);
                    break;
                case 'url':
                    $value = $this->faker->url();
                    break;
                case 'phone_number':
                    $value = 'FR+33749119783';
                    break;
                case 'date':
                    $value = $this->faker->date();
                    break;
                case 'select':
                    $value = $this->generateSelectValue($property);
                    break;
                case 'multi_select':
                    $value = $this->generateMultiSelectValues($property);
                    break;
                case 'files':
                    $value = null; // TODO: Will do this in future
                    break;
            }
            $data[$property['id']] = $value;
        });

        if (!$this->answerFormat) {
            $data = $this->formatAsSubmissionData($data);
        }

        return array_merge($data, $mergeData);
    }

    private function formatAsSubmissionData($data)
    {
        return $data;
    }

    private function generateSelectValue($property)
    {
        if (empty($property['options'])) {
            return null;
        }

        $option = $this->faker->randomElement($property['options']);
        return $option['id'];
    }

    private function generateMultiSelectValues($property)
    {
        if (empty($property['options'])) {
            return [];
        }

        $numOptions = count($property['options']);
        $numToSelect = $this->faker->numberBetween(1, min(3, $numOptions));

        $selectedOptions = $this->faker->randomElements(
            array_column($property['options'], 'id'),
            $numToSelect
        );

        return $selectedOptions;
    }
}
