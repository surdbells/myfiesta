import type { SelectOption } from '@myfiesta/ui';

/**
 * Where events happen, as the forms offer it.
 *
 * One copy. The create and edit forms each kept their own province list, and
 * two lists of the same thing are how one of them ends up missing Nunavut.
 */

/** Currency follows the country, because in practice it always does. */
export const COUNTRIES = [
  { code: 'CA', name: 'Canada', currency: 'CAD' as const, zone: 'America/Toronto' },
  { code: 'NG', name: 'Nigeria', currency: 'NGN' as const, zone: 'Africa/Lagos' },
];

/** Canada charges tax by province, so the province is not optional there. */
export const PROVINCES = [
  { code: 'AB', name: 'Alberta' },
  { code: 'BC', name: 'British Columbia' },
  { code: 'MB', name: 'Manitoba' },
  { code: 'NB', name: 'New Brunswick' },
  { code: 'NL', name: 'Newfoundland and Labrador' },
  { code: 'NS', name: 'Nova Scotia' },
  { code: 'NT', name: 'Northwest Territories' },
  { code: 'NU', name: 'Nunavut' },
  { code: 'ON', name: 'Ontario' },
  { code: 'PE', name: 'Prince Edward Island' },
  { code: 'QC', name: 'Quebec' },
  { code: 'SK', name: 'Saskatchewan' },
  { code: 'YT', name: 'Yukon' },
];

export const COUNTRY_OPTIONS: SelectOption[] = COUNTRIES.map((c) => ({ value: c.code, label: c.name }));

/** With the code as a hint, so typing "ON" finds Ontario. */
export const PROVINCE_OPTIONS: SelectOption[] = PROVINCES.map((p) => ({ value: p.code, label: p.name, hint: p.code }));

/**
 * The category choices, with "none" first since a category is optional.
 *
 * An event already filed under something no longer on the list keeps it as a
 * choice, marked, so opening the form does not silently drop it on save.
 */
export function categoryOptions(categories: readonly string[], current: string | null = null): SelectOption[] {
  const options: SelectOption[] = [
    { value: '', label: 'No category' },
    ...categories.map((c) => ({ value: c, label: c })),
  ];

  if (current && !categories.includes(current)) {
    options.push({ value: current, label: current, hint: 'No longer on the list — choose another when you can' });
  }

  return options;
}
