import { Router } from 'express';

import { apiToken } from '@/Http/Middleware/ApiToken';
import { captionUpload, videoUpload } from '@/Http/Middleware/VideoUpload';
import { captionQueue, CaptionQueueService } from '@/Services/Caption/CaptionQueueService';
import { packageQueue, PackageQueueService } from '@/Services/PackageQueueService';
import { reencodeQueue, ReencodeQueueService } from '@/Services/ReencodeQueueService';

import { CaptionController } from '../Controllers/CaptionController';
import { HealthController } from '../Controllers/HealthController';
import { PackageController } from '../Controllers/PackageController';
import { ReencodeController } from '../Controllers/ReencodeController';

export class Routers {
    private readonly router: Router = Router();
    private readonly healthController: HealthController;
    private readonly packageController: PackageController;
    private readonly reencodeController: ReencodeController;
    private readonly captionController: CaptionController;

    public constructor(
        queue: PackageQueueService = packageQueue,
        reencodes: ReencodeQueueService = reencodeQueue,
        captions: CaptionQueueService = captionQueue,
    ) {
        this.healthController = new HealthController(queue, reencodes, captions);
        this.packageController = new PackageController(queue);
        this.reencodeController = new ReencodeController(reencodes);
        this.captionController = new CaptionController(captions);
        this.initializeRoutes();
    }

    public getRouter(): Router {
        return this.router;
    }

    private initializeRoutes(): void {
        this.router.get('/health', (req, res) => this.healthController.health(req, res));

        this.router.post('/package', apiToken.handle, (req, res) =>
            this.packageController.create(req, res),
        );

        this.router.post('/reencode', apiToken.handle, videoUpload.handle, (req, res, next) => {
            void this.reencodeController.create(req, res).catch(next);
        });

        this.router.post('/videos', apiToken.handle, captionUpload.handle, (req, res, next) => {
            void this.captionController.create(req, res).catch(next);
        });

        this.router.post('/videos/:uuid/transcription', (req, res, next) => {
            void this.captionController.transcription(req, res).catch(next);
        });

        this.router.get('/videos', apiToken.handle, (req, res, next) => {
            void this.captionController.index(req, res).catch(next);
        });

        this.router.get('/videos/:uuid', apiToken.handle, (req, res, next) => {
            void this.captionController.show(req, res).catch(next);
        });

        this.router.get('/videos/:uuid/output/:variant', apiToken.handle, (req, res, next) => {
            void this.captionController.output(req, res).catch(next);
        });
    }
}
