import startEcho from './echo';

/**
 * The live half of the notification bell. A new notification arrives over the
 * WebSocket and is drawn here, in the browser, with NO request to the server:
 * a request would reset the idle timeout, so a screen nobody is using would
 * never sign out. Clicking it is real activity and goes through Livewire as usual.
 *
 * When Livewire re-renders the bell (opening one, "Mark all as read", a page
 * load), the server's list already contains these, so the live ones are dropped.
 */
export default function liveBell({ userId, unread }) {
    return {
        open: false,
        unread,
        live: [],
        toast: null,
        toastHandle: null,

        init() {
            window.Livewire?.hook?.('morphed', ({ component }) => {
                if (component.id !== this.$wire.$id) return;

                this.live = [];
                this.unread = Number(this.$root.dataset.unread ?? 0);
            });

            startEcho()
                ?.private(`App.Models.User.${userId}`)
                .listen('.notification.arrived', (notification) => this.arrive(notification));
        },

        destroy() {
            window.Echo?.leave(`App.Models.User.${userId}`);
            clearTimeout(this.toastHandle);
        },

        arrive(notification) {
            if (this.live.some((item) => item.id === notification.id)) return;

            this.live.unshift(notification);
            this.unread = notification.unread;
            this.toast = notification;

            clearTimeout(this.toastHandle);
            this.toastHandle = setTimeout(() => (this.toast = null), 8000);
        },

        get badge() {
            return this.unread > 9 ? '9+' : String(this.unread);
        },

        openLive(id) {
            this.toast = null;
            this.$wire.open(id);
        },
    };
}
