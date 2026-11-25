<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_inbox_states', function (Blueprint $table) {
            $table->foreignId('email_id')->primary()->constrained('emails')->cascadeOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedBigInteger('reset_revision')->default(0);
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('inbox_revision')->nullable()->default(0);
            $table->index(['email_id', 'inbox_revision']);
            $table->index(['email_id', 'is_pending', 'received_at', 'id'], 'messages_inbox_retained_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->installPostgresTriggers();
        } else {
            $this->installSqliteTriggers();
        }

        DB::statement('INSERT INTO mailbox_inbox_states (email_id) SELECT id FROM emails');
    }

    private function installPostgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION init_mailbox_inbox_state() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO mailbox_inbox_states (email_id) VALUES (NEW.id);
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER mailbox_inbox_init AFTER INSERT ON emails FOR EACH ROW EXECUTE FUNCTION init_mailbox_inbox_state();

            CREATE OR REPLACE FUNCTION track_mailbox_inbox_publication() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND
                    ROW(OLD.email_id, OLD.is_pending, OLD.received_at, OLD."from", OLD.subject, OLD.attachments::text)
                    IS NOT DISTINCT FROM
                    ROW(NEW.email_id, NEW.is_pending, NEW.received_at, NEW."from", NEW.subject, NEW.attachments::text)
                THEN
                    RETURN NEW;
                END IF;

                IF TG_OP <> 'INSERT' AND NOT OLD.is_pending AND OLD.email_id IS NOT NULL
                    AND (TG_OP <> 'DELETE' OR OLD.inbox_revision IS NOT NULL) THEN
                    UPDATE mailbox_inbox_states SET revision = revision + 1, reset_revision = revision + 1
                    WHERE email_id = OLD.email_id;
                    IF TG_OP = 'UPDATE' AND NEW.email_id = OLD.email_id AND NOT NEW.is_pending THEN
                        RETURN NEW;
                    END IF;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                IF NEW.is_pending OR NEW.email_id IS NULL THEN
                    NEW.inbox_revision := 0;
                ELSE
                    UPDATE mailbox_inbox_states SET revision = revision + 1
                    WHERE email_id = NEW.email_id RETURNING revision INTO NEW.inbox_revision;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER mailbox_inbox_messages BEFORE INSERT OR DELETE OR UPDATE OF email_id, is_pending, received_at, "from", subject, attachments
            ON messages FOR EACH ROW EXECUTE FUNCTION track_mailbox_inbox_publication();
            SQL);
    }

    private function installSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER mailbox_inbox_init AFTER INSERT ON emails BEGIN
                INSERT INTO mailbox_inbox_states (email_id) VALUES (NEW.id);
            END;
            CREATE TRIGGER mailbox_inbox_insert AFTER INSERT ON messages
            WHEN NOT NEW.is_pending AND NEW.email_id IS NOT NULL
            BEGIN
                UPDATE mailbox_inbox_states SET revision = revision + 1 WHERE email_id = NEW.email_id;
                UPDATE messages SET inbox_revision = (SELECT revision FROM mailbox_inbox_states WHERE email_id = NEW.email_id)
                WHERE id = NEW.id;
            END;
            CREATE TRIGGER mailbox_inbox_update AFTER UPDATE OF email_id, is_pending, received_at, "from", subject, attachments ON messages
            WHEN (OLD.email_id, OLD.is_pending, OLD.received_at, OLD."from", OLD.subject, OLD.attachments)
              IS NOT (NEW.email_id, NEW.is_pending, NEW.received_at, NEW."from", NEW.subject, NEW.attachments)
            BEGIN
                UPDATE mailbox_inbox_states SET revision = revision + 1, reset_revision = revision + 1
                WHERE email_id = OLD.email_id AND NOT OLD.is_pending;
                UPDATE mailbox_inbox_states SET revision = revision + 1
                WHERE email_id = NEW.email_id AND NOT NEW.is_pending AND (OLD.is_pending OR OLD.email_id IS NOT NEW.email_id);
                UPDATE messages SET inbox_revision = (SELECT revision FROM mailbox_inbox_states WHERE email_id = NEW.email_id)
                WHERE id = NEW.id AND NEW.email_id IS NOT NULL AND NOT NEW.is_pending AND (OLD.is_pending OR OLD.email_id IS NOT NEW.email_id);
            END;
            CREATE TRIGGER mailbox_inbox_delete AFTER DELETE ON messages
            WHEN NOT OLD.is_pending AND OLD.email_id IS NOT NULL AND OLD.inbox_revision IS NOT NULL
            BEGIN
                UPDATE mailbox_inbox_states SET revision = revision + 1, reset_revision = revision + 1
                WHERE email_id = OLD.email_id;
            END;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER mailbox_inbox_messages ON messages;
                DROP TRIGGER mailbox_inbox_init ON emails;
                DROP FUNCTION track_mailbox_inbox_publication();
                DROP FUNCTION init_mailbox_inbox_state();
                SQL);
        } else {
            DB::unprepared('DROP TRIGGER mailbox_inbox_insert; DROP TRIGGER mailbox_inbox_update; DROP TRIGGER mailbox_inbox_delete; DROP TRIGGER mailbox_inbox_init;');
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['email_id', 'inbox_revision']);
            $table->dropIndex('messages_inbox_retained_idx');
            $table->dropColumn('inbox_revision');
        });
        Schema::drop('mailbox_inbox_states');
    }
};
