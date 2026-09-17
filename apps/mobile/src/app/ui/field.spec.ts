import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { MfField } from './field';

@Component({
  imports: [MfField],
  template: `
    <mf-field label="Email">
      <input name="email" />
    </mf-field>

    <mf-field label="Password">
      <input #control name="password" />
    </mf-field>

    <mf-field label="About">
      <textarea id="chosen-by-hand" name="about"></textarea>
    </mf-field>
  `,
})
class Host {}

/**
 * A label has to point at something.
 *
 * This is the failure that looks like nothing: the page renders identically
 * whether or not the label is wired to the box beneath it, and the only person
 * who finds out is somebody using a screen reader, who reaches an input the
 * app never names.
 *
 * It had already happened. The component used to find the control only through
 * a `#control` reference, three screens were written without one, and six
 * inputs went unnamed until an audit went looking.
 */
describe('mf-field', () => {
  let host: HTMLElement;

  beforeEach(async () => {
    TestBed.configureTestingModule({ imports: [Host] });

    const fixture = TestBed.createComponent(Host);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    host = fixture.nativeElement as HTMLElement;
  });

  const wiring = () =>
    [...host.querySelectorAll('mf-field')].map((field) => {
      const label = field.querySelector('label');
      const control = field.querySelector('input, textarea');

      return {
        label: label?.textContent?.trim(),
        for: label?.getAttribute('for'),
        id: control?.id,
      };
    });

  it('names a control that was not marked in any way', () => {
    const [email] = wiring();

    expect(email.id).toBeTruthy();
    expect(email.for).toBe(email.id);
  });

  it('still uses an explicit #control reference', () => {
    const [, password] = wiring();

    expect(password.for).toBe(password.id);
  });

  it('leaves an id the screen chose for itself alone', () => {
    const [, , about] = wiring();

    // Screens point at their own ids from elsewhere; taking one over would
    // break whatever was pointing at it.
    expect(about.id).toBe('chosen-by-hand');
    expect(about.for).toBe('chosen-by-hand');
  });

  it('gives each field its own id', () => {
    const ids = wiring().map((field) => field.id);

    expect(new Set(ids).size).toBe(ids.length);
  });
});
