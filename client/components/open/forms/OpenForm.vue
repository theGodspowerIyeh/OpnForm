<template>
  <div v-if="isAutoSubmit">
    <p class="text-center p-4">
      <Loader class="h-6 w-6 text-nt-blue mx-auto" />
    </p>
  </div>
  <form
    v-else-if="dataForm"
    :style="computedStyle"
    @submit.prevent=""
  >
    <FormTimer
      ref="form-timer"
      :form="form"
    />
    <template v-if="form.show_progress_bar">
      <div
        v-if="isIframe"
        class="mb-4 p-2"
      >
        <div class="w-full h-2 bg-gray-200 dark:bg-gray-600 relative border rounded-full overflow-hidden">
          <div
            class="h-full transition-all duration-300 rounded-r-full"
            :class="{ 'w-0': formProgress === 0 }"
            :style="{ width: formProgress + '%', background: form.color }"
          />
        </div>
      </div>
      <div
        v-else
        class="fixed top-0 left-0 right-0 z-50"
      >
        <div class="w-full h-[0.2rem] bg-gray-200 dark:bg-gray-600 relative overflow-hidden">
          <div
            class="h-full transition-all duration-300"
            :class="{ 'w-0': formProgress === 0 }"
            :style="{ width: formProgress + '%', background: form.color }"
          />
        </div>
      </div>
    </template>
    <transition
      name="fade"
      mode="out-in"
    >
      <div
        :key="formPageIndex"
        class="form-group flex flex-wrap w-full"
      >
        <draggable
          :list="currentFields"
          group="form-elements"
          item-key="id"
          class="grid grid-cols-12 relative transition-all w-full"
          :class="{'rounded-md bg-blue-50 dark:bg-gray-800':draggingNewBlock}"
          ghost-class="ghost-item"
          filter=".not-draggable"
          :animation="200"
          :disabled="!adminPreview"
          @change="handleDragDropped"
        >
          <template #item="{element}">
            <open-form-field
              :field="element"
              :show-hidden="showHidden"
              :form="form"
              :data-form="dataForm"
              :data-form-value="dataFormValue"
              :theme="theme"
              :dark-mode="darkMode"
              :admin-preview="adminPreview"
            />
          </template>
        </draggable>
      </div>
    </transition>

    <!-- Captcha -->
    <div class="mb-3 px-2 mt-4 mx-auto w-max">
      <CaptchaInput
        v-if="form.use_captcha && isLastPage && hasCaptchaProviders && isCaptchaProviderAvailable"
        ref="captcha"
        :provider="form.captcha_provider"
        :form="dataForm"
        :language="form.language"
        :dark-mode="darkMode"
      />
    </div>

    <!--  Submit, Next and previous buttons  -->
    <div class="flex flex-wrap justify-center w-full">
      <open-form-button
        v-if="formPageIndex>0 && previousFieldsPageBreak && !loading"
        native-type="button"
        :color="form.color"
        :theme="theme"
        class="mt-2 px-8 mx-1"
        @click="previousPage"
      >
        {{ previousFieldsPageBreak.previous_btn_text }}
      </open-form-button>

      <slot
        v-if="isLastPage"
        name="submit-btn"
        :submit-form="submitForm"
      />
      <open-form-button
        v-else-if="currentFieldsPageBreak"
        native-type="button"
        :color="form.color"
        :theme="theme"
        class="mt-2 px-8 mx-1"
        :loading="dataForm.busy"
        @click.stop="nextPage"
      >
        {{ currentFieldsPageBreak.next_btn_text }}
      </open-form-button>
      <div v-if="!currentFieldsPageBreak && !isLastPage">
        {{ $t('forms.wrong_form_structure') }}
      </div>
    </div>
  </form>
</template>

<script>
import clonedeep from 'clone-deep'
import draggable from 'vuedraggable'
import OpenFormButton from './OpenFormButton.vue'
import CaptchaInput from '~/components/forms/components/CaptchaInput.vue'
import OpenFormField from './OpenFormField.vue'
import {pendingSubmission} from "~/composables/forms/pendingSubmission.js"
import FormLogicPropertyResolver from "~/lib/forms/FormLogicPropertyResolver.js"
import CachedDefaultTheme from "~/lib/forms/themes/CachedDefaultTheme.js"
import FormTimer from './FormTimer.vue'
import { storeToRefs } from 'pinia'

export default {
  name: 'OpenForm',
  components: {draggable, OpenFormField, OpenFormButton, CaptchaInput, FormTimer},
  props: {
    form: {
      type: Object,
      required: true
    },
    theme: {
      type: Object, default: () => {
        const theme = inject("theme", null)
        if (theme) {
          return theme.value
        }
        return CachedDefaultTheme.getInstance()
      }
    },
    loading: {
      type: Boolean,
      required: true
    },
    showHidden: {
      type: Boolean,
      default: false
    },
    fields: {
      type: Array,
      required: true
    },
    defaultDataForm: { type: [Object, null] },
    adminPreview: {type: Boolean, default: false}, // If used in FormEditorPreview
    urlPrefillPreview: {type: Boolean, default: false}, // If used in UrlFormPrefill
    darkMode: {
      type: Boolean,
      default: false
    }
  },
  emits: ['submit'],
  setup(props) {
    const recordsStore = useRecordsStore()
    const workingFormStore = useWorkingFormStore()
    const dataForm = ref(useForm())
    const config = useRuntimeConfig()

    const hasCaptchaProviders = computed(() => {
      return config.public.hCaptchaSiteKey || config.public.recaptchaSiteKey
    })

    return {
      dataForm,
      recordsStore,
      workingFormStore,
      isIframe: useIsIframe(),
      draggingNewBlock: computed(() => workingFormStore.draggingNewBlock),
      pendingSubmission: pendingSubmission(props.form),
      formPageIndex: storeToRefs(workingFormStore).formPageIndex,

      // Used for admin previews
      selectedFieldIndex: computed(() => workingFormStore.selectedFieldIndex),
      showEditFieldSidebar: computed(() => workingFormStore.showEditFieldSidebar),
      hasCaptchaProviders
    }
  },

  data() {
    return {
      // Page index
      /**
       * Used to force refresh components by changing their keys
       */
      isAutoSubmit: false,
    }
  },

  computed: {
    /**
     * Create field groups (or Page) using page breaks if any
     */
    fieldGroups() {
      if (!this.fields) return []
      const groups = []
      let currentGroup = []
      this.fields.forEach((field) => {
        if (field.type === 'nf-page-break' && this.isFieldHidden(field)) return
        currentGroup.push(field)
        if (field.type === 'nf-page-break') {
          groups.push(currentGroup)
          currentGroup = []
        }
      })
      groups.push(currentGroup)
      return groups
    },
    formProgress() {
      const requiredFields = this.fields.filter(field => field.required)
      if (requiredFields.length === 0) {
        return 100
      }
      const completedFields = requiredFields.filter(field => ![null, undefined, ''].includes(this.dataFormValue[field.id]))
      const progress = (completedFields.length / requiredFields.length) * 100
      return Math.round(progress)
    },
    currentFields: {
      get() {
        return this.fieldGroups[this.formPageIndex]
      },
      set(val) {
        // On re-order from the form, set the new order
        // Add the previous groups and next to val, and set the properties on working form
        const newFields = []
        this.fieldGroups.forEach((group, index) => {
          if (index < this.formPageIndex) {
            newFields.push(...group)
          } else if (index === this.formPageIndex) {
            newFields.push(...val)
          } else {
            newFields.push(...group)
          }
        })
        // set the properties on working_form store
        this.workingFormStore.setProperties(newFields)
      }
    },
    /**
     * Returns the page break block for the current group of fields
     */
    currentFieldsPageBreak() {
      // Last block from current group
      if (!this.currentFields?.length) return null
      const block = this.currentFields[this.currentFields.length - 1]
      if (block && block.type === 'nf-page-break') return block
      return null
    },
    previousFieldsPageBreak() {
      if (this.formPageIndex === 0) return null
      const previousFields = this.fieldGroups[this.formPageIndex - 1]
      const block = previousFields[previousFields.length - 1]
      if (block && block.type === 'nf-page-break') return block
      return null
    },
    /**
     * Returns true if we're on the last page
     * @returns {boolean}xs
     */
    isLastPage() {
      return this.formPageIndex === (this.fieldGroups.length - 1)
    },
    isPublicFormPage() {
      return this.$route.name === 'forms-slug'
    },
    dataFormValue() {
      // For get values instead of Id for select/multi select options
      const data = this.dataForm.data()
      const selectionFields = this.fields.filter((field) => {
        return ['select', 'multi_select'].includes(field.type)
      })
      selectionFields.forEach((field) => {
        if (data[field.id] !== undefined && data[field.id] !== null && Array.isArray(data[field.id])) {
          data[field.id] = data[field.id].map(option_nfid => {
            const tmpop = field[field.type].options.find((op) => {
              return (op.id === option_nfid)
            })
            return (tmpop) ? tmpop.name : option_nfid
          })
        }
      })
      return data
    },
    computedStyle() {
      return {
        '--form-color': this.form.color
      }
    },
    isCaptchaProviderAvailable() {
      const config = useRuntimeConfig()
      if (this.form.captcha_provider === 'recaptcha') {
        return !!config.public.recaptchaSiteKey
      } else if (this.form.captcha_provider === 'hcaptcha') {
        return !!config.public.hCaptchaSiteKey
      }
      return false
    }
  },

  watch: {
    form: {
      deep: true,
      handler() {
        this.initForm()
      }
    },
    fields: {
      deep: true,
      handler() {
        this.initForm()
      }
    },
    dataFormValue: {
      deep: true,
      handler() {
        if (this.isPublicFormPage && this.form && this.form.auto_save) {
          this.pendingSubmission.set(this.dataFormValue)
        }
      }
    },

    // These watchers ensure the form shows the correct page for the field being edited in admin preview
    selectedFieldIndex: {
      handler(newIndex) {
        if (this.adminPreview && this.showEditFieldSidebar) {
          this.setPageForField(newIndex)
        }
      }
    },
    showEditFieldSidebar: {
      handler(newValue) {
        if (this.adminPreview && newValue) {
          this.setPageForField(this.selectedFieldIndex)
        }
      }
    }
  },
  beforeMount() {
    this.initForm()
  },
  mounted() {
    nextTick(() => {
      if (this.$refs['form-timer']) {
        this.$refs['form-timer'].startTimer()
      }
    })
    if (import.meta.client && window.location.href.includes('auto_submit=true')) {
      this.isAutoSubmit = true
      this.submitForm()
    }
  },

  methods: {
    submitForm() {
      if (!this.isAutoSubmit && this.formPageIndex !== this.fieldGroups.length - 1) return

      if (this.form.use_captcha && import.meta.client) {
        this.$refs.captcha?.reset()
      }

      if (this.form.editable_submissions && this.form.submission_id) {
        this.dataForm.submission_id = this.form.submission_id
      }

      this.$refs['form-timer'].stopTimer()
      this.dataForm.completion_time = this.$refs['form-timer'].completionTime

      this.$emit('submit', this.dataForm, this.onSubmissionFailure)
    },
    /**
     *   Handle form submission failure
     */
    onSubmissionFailure() {
      this.$refs['form-timer'].startTimer()
      this.isAutoSubmit = false
      if (this.fieldGroups.length > 1) {
        this.showFirstPageWithError()
      }
      this.scrollToFirstError()
    },
    showFirstPageWithError() {
      for (let i = 0; i < this.fieldGroups.length; i++) {
        if (this.fieldGroups[i].some(field => this.dataForm.errors.has(field.id))) {
          this.formPageIndex = i
          break
        }
      }
    },
    scrollToFirstError() {
      if (import.meta.server) return
      const firstErrorElement = document.querySelector('.has-error')
      if (firstErrorElement) {
        window.scroll({
          top: window.scrollY + firstErrorElement.getBoundingClientRect().top - 60,
          behavior: 'smooth'
        })
      }
    },
    async getSubmissionData() {
      if (!this.form || !this.form.editable_submissions || !this.form.submission_id) {
        return null
      }
      await this.recordsStore.loadRecord(
        opnFetch('/forms/' + this.form.slug + '/submissions/' + this.form.submission_id).then((data) => {
          return {submission_id: this.form.submission_id, id: this.form.submission_id, ...data.data}
        }).catch((error) => {
          useAlert().error(error?.data?.message || 'Something went wrong')
          return null
        })
      )
      return this.recordsStore.getByKey(this.form.submission_id)
    },

     /**
     * Form initialization
     */
    async initForm() {
      if (this.defaultDataForm) {
        await nextTick(() => {
          this.dataForm.resetAndFill(this.defaultDataForm)
        })
        return
      }
      
      if (await this.tryInitFormFromEditableSubmission()) return
      if (this.tryInitFormFromPendingSubmission()) return

      await nextTick(() => {
        this.formPageIndex = 0
        this.initFormWithDefaultValues()
      })
    },
    async tryInitFormFromEditableSubmission() {
      if (this.isPublicFormPage && this.form.editable_submissions) {
        const submissionId = useRoute().query?.submission_id
        if (submissionId) {
          this.form.submission_id = submissionId
          const data = await this.getSubmissionData()
          if (data) {
            this.dataForm.resetAndFill(data)
            return true
          }
        }
      }
      return false
    },
    tryInitFormFromPendingSubmission() {
      if (this.isPublicFormPage && this.form.auto_save) {
        const pendingData = this.pendingSubmission.get()
        if (pendingData && Object.keys(pendingData).length !== 0) {
          this.updatePendingDataFields(pendingData)
          this.dataForm.resetAndFill(pendingData)
          return true
        }
      }
      return false
    },
    updatePendingDataFields(pendingData) {
      this.fields.forEach(field => {
        if (field.type === 'date' && field.prefill_today) {
          pendingData[field.id] = new Date().toISOString()
        }
      })
    },
    initFormWithDefaultValues() {
      const formData = {}
      const urlPrefill = this.isPublicFormPage ? new URLSearchParams(window.location.search) : null

      this.fields.forEach(field => {
        if (field.type.startsWith('nf-')) return

        this.handleUrlPrefill(field, formData, urlPrefill)
        this.handleDefaultPrefill(field, formData)
      })

      this.dataForm.resetAndFill(formData)
    },
    handleUrlPrefill(field, formData, urlPrefill) {
      if (!urlPrefill) return

      const prefillValue = (() => {
        const val = urlPrefill.get(field.id)
        try {
          return typeof val === 'string' && val.startsWith('{') ? JSON.parse(val) : val
        } catch (e) {
          return val
        }
      })()
      const arrayPrefillValue = urlPrefill.getAll(field.id + '[]')

      if (typeof prefillValue === 'object' && prefillValue !== null) {
        formData[field.id] = { ...prefillValue }
      } else if (prefillValue !== null) {
        formData[field.id] = field.type === 'checkbox' ? this.parseBooleanValue(prefillValue) : prefillValue
      } else if (arrayPrefillValue.length > 0) {
        formData[field.id] = arrayPrefillValue
      }
    },
    parseBooleanValue(value) {
      return value === 'true' || value === '1'
    },
    handleDefaultPrefill(field, formData) {
      if (field.type === 'date' && field.prefill_today) {
        formData[field.id] = new Date().toISOString()
      } else if (field.type === 'matrix' && !formData[field.id]) {
        formData[field.id] = {...field.prefill}
      } else if (!(field.id in formData)) {
        formData[field.id] = field.prefill
      }
    },
    
    /**
     * Page Navigation
     */
    previousPage() {
      this.formPageIndex--
      this.scrollToTop()
    },
    nextPage() {
      if (this.adminPreview || this.urlPrefillPreview) {
        this.formPageIndex++
        this.scrollToTop()
        return false
      }
      const fieldsToValidate = this.currentFields.map(f => f.id)
      this.dataForm.busy = true
      this.dataForm.validate('POST', '/forms/' + this.form.slug + '/answer', {}, fieldsToValidate)
        .then(() => {
          this.formPageIndex++
          this.dataForm.busy = false
          this.scrollToTop()
        }).catch(this.handleValidationError)
      return false
    },
    scrollToTop() {
      window.scrollTo({ top: 0, behavior: 'smooth' })
    },
    handleValidationError(error) {
      console.error(error)
      if (error?.data?.message) {
        useAlert().error(error.data.message)
      }
      this.dataForm.busy = false
    },
    isFieldHidden(field) {
      return (new FormLogicPropertyResolver(field, this.dataFormValue)).isHidden()
    },
    getTargetFieldIndex(currentFieldPageIndex) {
      return this.formPageIndex > 0
        ? this.fieldGroups.slice(0, this.formPageIndex).reduce((sum, group) => sum + group.length, 0) + currentFieldPageIndex
        : currentFieldPageIndex
    },
    handleDragDropped(data) {
      if (data.added) {
        const targetIndex = this.getTargetFieldIndex(data.added.newIndex)
        this.workingFormStore.addBlock(data.added.element, targetIndex, false)
      }
      if (data.moved) {
        const oldTargetIndex = this.getTargetFieldIndex(data.moved.oldIndex)
        const newTargetIndex = this.getTargetFieldIndex(data.moved.newIndex)
        this.workingFormStore.moveField(oldTargetIndex, newTargetIndex)
      }
    },
    setPageForField(fieldIndex) {
      if (fieldIndex === -1) return

      let currentIndex = 0
      for (let i = 0; i < this.fieldGroups.length; i++) {
        currentIndex += this.fieldGroups[i].length
        if (currentIndex > fieldIndex) {
          this.formPageIndex = i
          return
        }
      }
      this.formPageIndex = this.fieldGroups.length - 1
    },
  }
}
</script>

<style lang='scss' scoped>
.ghost-item {
  @apply bg-blue-100 dark:bg-blue-900 rounded-md;
}
</style>
