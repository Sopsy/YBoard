import YQuery from '../../YQuery';
import YBoard from '../../YBoard';

class File
{
    constructor()
    {
        let that = this;

        // Video volume change
        document.addEventListener('volumechange', function()
        {
            localStorage.setItem('videoVolume', that.volume);
        });
    }

    bindEvents(parent)
    {
        let that = this;

        parent.querySelectorAll('.thumbnail .image').forEach(function(elm)
        {
            elm.addEventListener('click', that.expand);
        });

        parent.querySelectorAll('.thumbnail .media').forEach(function(elm)
        {
            elm.addEventListener('click', function(e) {
                that.playMedia(e, that.stopAllMedia);
            });
        });

        parent.querySelectorAll('.e-stop-media').forEach(function(elm)
        {
            elm.addEventListener('click', that.stopAllMedia);
        });
    }

    delete(id)
    {
        if (!confirm(messages.confirmDeleteFile)) {
            return false;
        }

        YQuery.post('/api/post/deletefile', {
            'post_id': id,
            'loadFunction': function()
            {
                this.getElm(id).find('figure').remove();
                YBoard.Toast.success(messages.fileDeleted);
            },
        });
    }

    expand(e)
    {
        function changeSrc(img, src)
        {
            let eolFn = expandOnLoad;
            function expandOnLoad(e)
            {
                e.target.removeEventListener('load', eolFn);
                delete e.target.dataset.expanding;
                clearTimeout(e.target.loading);
                let overlay = e.target.parentNode.querySelector('div.overlay');
                if (overlay !== null) {
                    overlay.remove();
                }
            }

            img.dataset.expanding = true;
            img.loading = setTimeout(function()
            {
                let overlay = document.createElement('div');
                overlay.classList.add('overlay', 'center');
                overlay.innerHTML = YBoard.spinnerHtml();

                img.parentNode.appendChild(overlay);
            }, 200);

            img.addEventListener('load', eolFn);
            img.setAttribute('src', src);
        }

        e.preventDefault();
        if (typeof e.target.dataset.expanded === 'undefined') {
            // Expand
            e.target.dataset.expanded = e.target.getAttribute('src');
            changeSrc(e.target, e.target.parentNode.getAttribute('href'));
            e.target.closest('.post-file').classList.remove('thumbnail');
            e.target.closest('.message').classList.add('full');
        } else {
            // Restore thumbnail
            changeSrc(e.target, e.target.dataset.expanded);
            delete e.target.dataset.expanded;
            e.target.closest('.post-file').classList.add('thumbnail');
            e.target.closest('.message').classList.remove('full');

            // Scroll to top of image
            let elmTop = e.target.getBoundingClientRect().top + window.scrollY;
            if (elmTop < window.scrollY) {
                window.scrollTo(0, elmTop);
            }
        }
    }

    playMedia(e, stopAllMedia)
    {
        e.preventDefault();

        stopAllMedia();

        let fileId = e.target.closest('figure').dataset.id;

        if (typeof e.target.dataset.loading !== 'undefined') {
            return false;
        }

        e.target.dataset.loading = true;

        let loading = setTimeout(function()
        {
            let overlay = document.createElement('div');
            overlay.classList.add('overlay', 'bottom', 'left');
            overlay.innerHTML = YBoard.spinnerHtml();
            e.target.appendChild(overlay);
        }, 200);

        YQuery.post('/api/file/getmediaplayer', {'fileId': fileId}).onLoad(function(xhr)
        {
            let figure = e.target.closest('.post-file');
            figure.classList.remove('thumbnail');
            figure.classList.add('media-player-container');
            e.target.closest('.message').classList.add('full');

            let data = document.createElement('template');
            data.innerHTML = xhr.responseText;

            // Bind events etc.
            YBoard.initElement(data.content);

            figure.insertBefore(data.content, figure.firstElementChild);

            let volume = localStorage.getItem('videoVolume');
            if (volume !== null) {
                e.target.parentNode.querySelector('video').volume = volume;
            }
        }).onEnd(function()
        {
            clearTimeout(loading);
            e.target.querySelectorAll('div.overlay').forEach(function(elm) {
                elm.remove();
            });
            delete e.target.dataset.loading;
        });
    }

    stopAllMedia()
    {
        document.querySelectorAll('.media-player-container').forEach(function(elm) {
            let video = elm.querySelector('video');
            video.pause();
            video.remove();

            elm.classList.remove('media-player-container');
            elm.classList.add('thumbnail');
        });
    }
}

export default File;
