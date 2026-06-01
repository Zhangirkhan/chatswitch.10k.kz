export type ContactFieldTypeId =
    | 'string'
    | 'text'
    | 'list'
    | 'date'
    | 'datetime'
    | 'address'
    | 'link'
    | 'file'
    | 'money'
    | 'boolean'
    | 'number';

export type ContactFieldDefinition = {
    id: number;
    code: string;
    label: string;
    type: ContactFieldTypeId;
    section: string;
    group: string;
    group_label: string;
    is_system: boolean;
    is_visible: boolean;
    options: { choices?: string[] } | null;
    sort_order: number;
};

export type ContactFieldTypeOption = {
    id: ContactFieldTypeId;
    label: string;
    description: string | null;
};

export const ADDABLE_FIELD_TYPES: ContactFieldTypeOption[] = [
    { id: 'list', label: 'Список', description: 'Выбор одного или нескольких значений из списка' },
    { id: 'datetime', label: 'Дата/время', description: 'Дата и время с календарём' },
    { id: 'date', label: 'Дата', description: 'Дата с календарём' },
    { id: 'address', label: 'Адрес', description: 'Хранение адресной информации' },
    { id: 'link', label: 'Ссылка', description: 'Ссылки на веб-страницы' },
    { id: 'file', label: 'Файл', description: 'Изображения и документы (URL)' },
    { id: 'money', label: 'Деньги', description: 'Сумма с валютой' },
    { id: 'boolean', label: 'Да/Нет', description: 'Быстрый ответ да или нет' },
    { id: 'number', label: 'Число', description: 'Числовые данные' },
    { id: 'string', label: 'Строка', description: 'Короткая текстовая строка' },
    { id: 'text', label: 'Текст', description: 'Многострочный текст' },
];
