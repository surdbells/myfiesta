import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Seo } from '../../core/seo';

/**
 * The "My tickets" door for a platform with no buyer accounts.
 *
 * The tickets themselves live behind the tokened link in the confirmation
 * email — that is the security model, not an oversight — so what this page
 * can honestly do is say that, and take an order reference to the order page,
 * which restates where the tickets went.
 */
@Component({
  selector: 'mf-find-tickets',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './find-tickets.html',
})
export class FindTickets {
  private readonly router = inject(Router);
  private readonly seo = inject(Seo);

  readonly reference = signal('');

  constructor() {
    this.seo.forListing(
      'My tickets',
      'Find your myFiesta tickets from your confirmation email.',
      'https://myfiesta.ca/tickets',
    );
  }

  lookUp(): void {
    const ref = this.reference().trim().toUpperCase();
    if (!ref) return;

    void this.router.navigate(['/order', ref]);
  }
}
