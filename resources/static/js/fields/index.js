/**
 * Field registry: PHP `type` => Vue control component.
 *
 * `date` and `datetime` share one control — `n-date-picker` covers both through
 * its `type` prop.
 */

import Checkbox from './checkbox.js'
import KeyValue from './keyvalue.js'
import Tags from './tags.js'
import Icon from './icon.js'
import Rate from './rate.js'
import Color from './color.js'
import DatePicker from './date.js'
import Number from './number.js'
import Password from './password.js'
import Radio from './radio.js'
import Select from './select.js'
import Slider from './slider.js'
import Switch from './switch.js'
import Text from './text.js'
import Textarea from './textarea.js'
import Time from './time.js'
import Transfer from './transfer.js'
import TreeSelect from './treeSelect.js'
import Upload from './upload.js'

export const FIELD_COMPONENTS = {
  text: Text,
  password: Password,
  textarea: Textarea,
  number: Number,
  radio: Radio,
  checkbox: Checkbox,
  select: Select,
  switch: Switch,
  color: Color,
  rate: Rate,
  icon: Icon,
  tags: Tags,
  keyvalue: KeyValue,
  'key-value': KeyValue,
  slider: Slider,
  date: DatePicker,
  datetime: DatePicker,
  time: Time,
  transfer: Transfer,
  upload: Upload,
  treeSelect: TreeSelect,
}

export function resolveField(type) {
  return FIELD_COMPONENTS[type] || Text
}

export const FIELD_TYPES = Object.keys(FIELD_COMPONENTS)
